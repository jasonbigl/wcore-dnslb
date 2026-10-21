<?php

/**
 * 在真实 Route 53 上验证 dnslb 的 DNS 记录结构和同步逻辑。会在指定子域名下创建临时记录，结束时清理。
 *
 * 用法：
 *   php tools/verify-route53.php --zone <ZoneId> --domain <测试子域名> [--keep]
 *
 * 密钥读取环境变量 AWS_KEY、AWS_SECRET，未设置时读取仓库根目录的 .env。
 *
 * 例如 --domain dnslb-verify.example.com，或测试通配符：--domain '*.dnslb-verify.example.com'
 * （必须位于该托管区域内、低于区域根，且未被其他记录使用。不要直接用正式的 *.example.com 测试）。
 * 需要的 IAM 权限：route53:GetHostedZone、ListResourceRecordSets、ChangeResourceRecordSets、GetChange、TestDNSAnswer。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/env.php';

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Dns\Route53Sync;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Support\StreamLogger;

const JP_CLIENT = '133.242.0.0';   // 日本的网段（模拟东京用户）
const DE_CLIENT = '85.214.0.0';    // 德国的网段（模拟法兰克福附近用户）

$options = getopt('', ['zone:', 'domain:', 'keep']);
$zone = $options['zone'] ?? null;
$domain = isset($options['domain']) ? strtolower(rtrim($options['domain'], '.')) : null;
$key = dnslb_env('AWS_KEY');
$secret = dnslb_env('AWS_SECRET');
if ($zone === null || $domain === null || !$key || !$secret) {
    fwrite(STDERR, "Usage: php tools/verify-route53.php --zone <ZoneId> --domain <test.example.com> [--keep]\n");
    if (!$key || !$secret) {
        fwrite(STDERR, "Missing AWS_KEY / AWS_SECRET (environment variables or .env in the repository root)\n");
    }
    exit(2);
}
foreach (['AWS_KEY' => $key, 'AWS_SECRET' => $secret] as $name => $value) {
    if (preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
        fwrite(STDERR, "{$name} contains whitespace or control characters (Windows line endings in the env file?)\n");
        exit(2);
    }
}
if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
    fwrite(STDERR, "--domain must be an unused test subdomain such as dnslb-verify.example.com or *.dnslb-verify.example.com\n");
    exit(2);
}
$wildcard = strpos($domain, '*.') === 0;
$base = $wildcard ? substr($domain, 2) : $domain;
// 通配符时用具体的子域名查询，验证通配符能匹配到
$queryName = $wildcard ? 'customer-1.' . $base : $domain;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$makeApp = static function (string $routing, Database $db) use ($zone, $domain, $base, $key, $secret): Application {
    $launch = static function (string $region): array {
        return ['provider' => 'aws', 'region' => $region, 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc'];
    };
    $config = Config::fromArray([
        'service' => 'verify',
        'state' => ':memory:',
        'aws' => ['key' => $key, 'secret' => $secret],
        'providers' => ['aws' => ['driver' => 'ec2']],
        'dns' => ['zone_id' => $zone, 'name' => $domain, 'ttl' => 60, 'ipv6' => true, 'pool_routing' => $routing],
        'health_check' => ['pool_failover_after' => 180, 'host' => 'health.' . $base],
        'user_data' => ['template' => 'cloud-init.yaml', 'vars' => [
            'cert_center_url' => 'https://example.invalid',
            'grafana_remote_write_url' => 'https://example.invalid',
            'grafana_username' => 'unused',
            'grafana_write_token' => 'unused',
        ]],
        'pools' => [
            'tokyo' => ['latency_region' => 'ap-northeast-1', 'max' => 3, 'launch' => [$launch('ap-northeast-1')]],
            'frankfurt' => ['latency_region' => 'eu-central-1', 'max' => 3, 'launch' => [$launch('eu-central-1')]],
        ],
    ], (string) realpath(__DIR__ . '/../examples'));
    return new Application($config, 'verify', false, [
        'database' => $db,
        'logger' => new StreamLogger(null, 'info', 'verify'),
    ]);
};

$db = Database::open(':memory:');
$app = $makeApp('multivalue', $db);
$now = $app->clock()->now();

$addNode = static function (string $id, string $pool, string $v4, string $v6) use ($app, $now): Node {
    $node = new Node($id, $pool, 'aws', 'x', 0, 10, $now);
    $node->transition(Node::IN_SERVICE, $now);
    $node->ipv4 = $v4;
    $node->ipv6 = $v6;
    $node->v4->healthy = $node->v6->healthy = true;
    $node->v4->since = $node->v6->since = $now;
    $app->nodes()->insert($node, $now);
    $app->nodes()->saveHealth($node);
    return $node;
};

$waitInSync = static function (array $changeIds) use ($app): void {
    foreach ($changeIds as $id) {
        for ($i = 0; $i < 30 && $app->route53()->getChange($id) !== 'INSYNC'; $i++) {
            sleep(2);
        }
    }
};

$answer = static function (Application $app, string $type, string $client) use ($zone, $queryName): array {
    $result = $app->route53()->testDnsAnswer($zone, $queryName, $type, $client, 24);
    // TestDNSAnswer 返回的 IPv6 是完整写法，统一成压缩写法再比较
    $values = array_map([RecordSet::class, 'normalizeValue'], $result['values']);
    sort($values);
    return $values;
};

$sync = static function (Application $app, string $label) use ($check, $waitInSync): bool {
    try {
        $result = $app->dnsSync()->sync();
        $waitInSync($result->changeIds);
        $check(true, $label . '（' . count($result->changes) . ' 项变更）');
        return true;
    } catch (\Throwable $e) {
        $check(false, $label . '：' . $e->getMessage());
        return false;
    }
};

try {
    $zoneInfo = $app->route53()->getHostedZone($zone);
    if ($base === $zoneInfo['name'] || substr($base, -strlen('.' . $zoneInfo['name'])) !== '.' . $zoneInfo['name']) {
        // 不在区域内，或者就是区域根（例如正式的 *.example.com）：拒绝测试，避免影响线上流量
        fwrite(STDERR, "--domain must be a test name below {$zoneInfo['name']}, e.g. *.dnslb-verify.{$zoneInfo['name']} (refusing to touch the zone apex or names outside the zone)\n");
        exit(2);
    }
    $check(true, "托管区域 {$zoneInfo['name']} 包含 {$domain}" . ($wildcard ? "，用 {$queryName} 查询通配符" : ''));
    $existing = $app->dnsSync()->fetchActual();
    if ($existing !== []) {
        echo "注意：{$domain} 下已有 " . count($existing) . " 条 dnslb 记录（可能是上次中断留下的），将一并接管和清理\n";
    }

    echo "\n== 1. 发布：latency Alias → 多值回答记录 ==\n";
    $addNode('verify-tokyo-a', 'tokyo', '192.0.2.1', '2001:db8::1');
    $tokyoB = $addNode('verify-tokyo-b', 'tokyo', '192.0.2.2', '2001:db8::2');
    $addNode('verify-frankfurt-a', 'frankfurt', '198.51.100.1', '2001:db8:1::1');
    if ($sync($app, '创建多值记录和指向它们的 latency Alias 记录')) {
        $check($answer($app, 'A', JP_CLIENT) === ['192.0.2.1', '192.0.2.2'], '日本用户 A 记录返回东京两个节点：' . implode(',', $answer($app, 'A', JP_CLIENT)));
        $check($answer($app, 'AAAA', JP_CLIENT) === ['2001:db8::1', '2001:db8::2'], '日本用户 AAAA 记录返回东京两个节点：' . implode(',', $answer($app, 'AAAA', JP_CLIENT)));
        $check($answer($app, 'A', DE_CLIENT) === ['198.51.100.1'], '德国用户返回法兰克福节点：' . implode(',', $answer($app, 'A', DE_CLIENT)));
        $again = $app->dnsSync()->sync();
        $check(!$again->hasChanges(), '再次同步没有变更（读回的记录与期望一致）');
    }

    echo "\n== 2. 摘除不健康节点 ==\n";
    $tokyoA = $app->nodes()->find('verify-tokyo-a');
    $tokyoA->v4->healthy = false;
    $app->nodes()->saveHealth($tokyoA);
    if ($sync($app, '摘除 verify-tokyo-a 的 A 记录')) {
        $check($answer($app, 'A', JP_CLIENT) === ['192.0.2.2'], '日本用户只拿到健康节点：' . implode(',', $answer($app, 'A', JP_CLIENT)));
        $check($answer($app, 'AAAA', JP_CLIENT) === ['2001:db8::1', '2001:db8::2'], 'IPv6 不受影响');
    }

    echo "\n== 3. 整池故障超过宽限期，切换到其他区域 ==\n";
    $tokyoB->v4->healthy = false;
    $tokyoB->v4->since = $now - 600;
    $app->nodes()->saveHealth($tokyoB);
    $pool = $app->pools()->get('tokyo');
    $pool->setDownSince('v4', $now - 600);
    $app->pools()->saveHealth($pool);
    if ($sync($app, '撤下东京池的 A 记录和 latency 记录')) {
        $check($answer($app, 'A', JP_CLIENT) === ['198.51.100.1'], '日本用户被切到法兰克福：' . implode(',', $answer($app, 'A', JP_CLIENT)));
        $check($answer($app, 'AAAA', JP_CLIENT) === ['2001:db8::1', '2001:db8::2'], '日本用户 IPv6 仍在东京');
    }

    echo "\n== 4. 恢复并切换为加权路由 ==\n";
    foreach ([$tokyoA, $tokyoB] as $node) {
        $node->v4->healthy = true;
        $app->nodes()->saveHealth($node);
    }
    $pool->setDownSince('v4', null);
    $app->pools()->saveHealth($pool);
    $weighted = $makeApp('weighted', $db);
    if ($sync($weighted, '多值 → 加权（同一批次内摘下 latency、替换记录、再挂回）')) {
        $values = $answer($weighted, 'A', JP_CLIENT);
        $check(count($values) === 1 && in_array($values[0], ['192.0.2.1', '192.0.2.2'], true), '加权路由每次返回一个东京节点：' . implode(',', $values));
    }
} catch (\Throwable $e) {
    $check(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if (!isset($options['keep'])) {
        echo "\n== 清理 ==\n";
        try {
            $leftover = $app->dnsSync()->fetchActual();
            $changes = Route53Sync::diff([], $leftover, $domain);
            if ($changes !== []) {
                $id = $app->route53()->changeRecordSets($zone, $changes, 'dnslb verify cleanup');
                $waitInSync([$id]);
            }
            $check($app->dnsSync()->fetchActual() === [], '已删除全部 ' . count($changes) . ' 条临时记录');
        } catch (\Throwable $e) {
            $check(false, '清理失败，请手动删除 ' . $domain . ' 下的记录：' . $e->getMessage());
        }
    }
}

echo $failures === 0 ? "\n全部验证通过\n" : "\n{$failures} 项验证失败\n";
exit($failures === 0 ? 0 : 1);
