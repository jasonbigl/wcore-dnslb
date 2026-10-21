<?php

/**
 * 在真实 Cloudflare 上验证 dnslb 的记录同步逻辑。会在指定的测试名称下创建临时记录，结束时清理。
 *
 * 用法：
 *   php tools/verify-cloudflare.php --zone <区域 ID> --domain <测试名称> [--keep]
 *
 * 令牌读取环境变量 CF_TOKEN，未设置时读取仓库根目录的 .env。
 *
 * 例如 --domain '*.dnslb-verify.example.com'（推荐通配符：每次用新的子域名查询解析结果，不受 DNS 缓存影响），
 * 或 --domain dnslb-verify.example.com（只验证 API，不验证解析）。
 * 测试名称必须位于该区域内、低于区域根，并且没有任何现有记录。不要直接用正式域名测试。
 * 令牌需要该区域的 Zone - DNS - Edit 和 Zone - Zone - Read 权限。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/env.php';

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Dns\CloudflareSync;
use Wcore\Dnslb\Dns\DnsSync;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Support\StreamLogger;

// 文档用地址（RFC 5737 / RFC 3849），不会真的有流量发过去
const FOREIGN_IP = '203.0.113.9';
const FOREIGN_CNAME = 'dnslb-verify-old.example.net';

$options = getopt('', ['zone:', 'domain:', 'keep']);
$zone = $options['zone'] ?? null;
$domain = isset($options['domain']) ? strtolower(rtrim($options['domain'], '.')) : null;
$token = dnslb_env('CF_TOKEN');
if ($zone === null || $domain === null || !$token) {
    fwrite(STDERR, "Usage: php tools/verify-cloudflare.php --zone <zone id> --domain <'*.dnslb-verify.example.com'> [--keep]\n");
    if (!$token) {
        fwrite(STDERR, "Missing CF_TOKEN (environment variable or .env in the repository root)\n");
    }
    exit(2);
}
if (preg_match('/[\s\x00-\x1f\x7f]/', $token)) {
    fwrite(STDERR, "CF_TOKEN contains whitespace or control characters (Windows line endings in the env file?)\n");
    exit(2);
}
if (!preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
    fwrite(STDERR, "--domain must be an unused test name such as dnslb-verify.example.com or *.dnslb-verify.example.com\n");
    exit(2);
}
$wildcard = strpos($domain, '*.') === 0;
$base = $wildcard ? substr($domain, 2) : $domain;

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$makeApp = static function (Database $db, bool $proxied) use ($zone, $domain, $base, $token): Application {
    $config = Config::fromArray([
        'service' => 'verify',
        'state' => ':memory:',
        'cloudflare' => ['api_token' => $token],
        'providers' => ['linode' => ['driver' => 'linode', 'token' => 'unused']],
        'dns' => ['provider' => 'cloudflare', 'zone_id' => $zone, 'name' => $domain, 'ipv6' => true, 'proxied' => $proxied],
        'health_check' => ['host' => 'health.' . $base],
        'user_data' => ['template' => 'cloud-init.yaml', 'vars' => [
            'cert_center_url' => 'https://example.invalid',
            'grafana_remote_write_url' => 'https://example.invalid',
            'grafana_username' => 'unused',
            'grafana_write_token' => 'unused',
        ]],
        'pools' => [
            'tokyo' => ['max' => 3, 'launch' => [['provider' => 'linode', 'region' => 'ap-northeast', 'type' => 'g6-standard-2', 'image' => 'linode/debian12']]],
            'frankfurt' => ['max' => 3, 'launch' => [['provider' => 'linode', 'region' => 'eu-central', 'type' => 'g6-standard-2', 'image' => 'linode/debian12']]],
        ],
    ], (string) realpath(__DIR__ . '/../examples'));
    return new Application($config, 'verify', false, [
        'database' => $db,
        'logger' => new StreamLogger(null, 'info', 'verify'),
    ]);
};

$db = Database::open(':memory:');
$app = $makeApp($db, true);
$client = $app->cloudflare();
$now = $app->clock()->now();

$addNode = static function (string $id, string $pool, string $v4, string $v6) use ($app, $now): Node {
    $node = new Node($id, $pool, 'linode', 'x', 0, 10, $now);
    $node->transition(Node::IN_SERVICE, $now);
    $node->ipv4 = $v4;
    $node->ipv6 = $v6;
    $node->v4->healthy = $node->v6->healthy = true;
    $node->v4->since = $node->v6->since = $now;
    $app->nodes()->insert($node, $now);
    $app->nodes()->saveHealth($node);
    return $node;
};

/** @return string[] 例如 "A 192.0.2.1 proxied [dnslb node verify-tokyo-a]" */
$records = static function () use ($client, $zone, $domain): array {
    $result = array_map(static function (DnsRecord $r): string {
        return "{$r->type} " . DnsRecord::normalizeAddress($r->content) . ($r->proxied ? ' proxied' : " ttl={$r->ttl}") . ($r->comment !== null ? " [{$r->comment}]" : '');
    }, $client->listRecords($zone, $domain));
    sort($result);
    return $result;
};

$sync = static function (DnsSync $dns, string $label) use ($check): bool {
    try {
        $result = $dns->sync();
        $check(true, $label . '（' . count($result->changes) . ' 项变更）');
        return true;
    } catch (\Throwable $e) {
        $check(false, $label . '：' . $e->getMessage());
        return false;
    }
};

$zoneActive = false;
// 依次尝试的 DoH JSON 接口（部分网络无法访问 Cloudflare 的 DoH）；第一个能连通的会被继续使用
$dohEndpoints = ['https://cloudflare-dns.com/dns-query', 'https://dns.google/resolve'];
/**
 * 通过 DoH 解析一个从未查询过的子域名（只用于通配符），等待记录传播。
 *
 * @return string[]|null 解析结果；不能验证时为 null
 */
$resolve = static function (string $type, callable $done) use ($app, $base, $wildcard, &$zoneActive, &$dohEndpoints): ?array {
    if (!$wildcard || !$zoneActive) {
        return null;
    }
    $answers = [];
    for ($i = 0; $i < 10; $i++) {
        $name = 'check-' . bin2hex(random_bytes(4)) . '.' . $base;
        $response = null;
        while ($dohEndpoints !== []) {
            try {
                $response = $app->transport()->send(new Request('GET', $dohEndpoints[0] . '?' . http_build_query(['name' => $name, 'type' => $type]), ['Accept' => 'application/dns-json']));
                break;
            } catch (HttpException $e) {
                echo "注意：无法访问 {$dohEndpoints[0]}（{$e->getMessage()}）\n";
                array_shift($dohEndpoints);
            }
        }
        if ($response === null) {
            echo "注意：所有 DoH 接口都无法访问，跳过解析验证\n";
            return null;
        }
        $data = json_decode($response->body, true);
        $answers = [];
        foreach ($data['Answer'] ?? [] as $answer) {
            if ((int) $answer['type'] === ($type === 'AAAA' ? 28 : 1)) {
                $answers[] = DnsRecord::normalizeAddress((string) $answer['data']);
            }
        }
        sort($answers);
        if ($done($answers)) {
            break;
        }
        sleep(3);
    }
    return $answers;
};

$created = [];
try {
    $zoneInfo = $client->getZone($zone);
    if ($base === $zoneInfo['name'] || substr($base, -strlen('.' . $zoneInfo['name'])) !== '.' . $zoneInfo['name']) {
        // 不在区域内，或者就是区域根（例如正式的 *.example.com）：拒绝测试，避免影响线上流量
        fwrite(STDERR, "--domain must be a test name below {$zoneInfo['name']}, e.g. *.dnslb-verify.{$zoneInfo['name']} (refusing to touch the zone apex or names outside the zone)\n");
        exit(2);
    }
    $zoneActive = $zoneInfo['status'] === 'active';
    $check(true, "区域 {$zoneInfo['name']}（{$zoneInfo['status']}）包含 {$domain}");
    if (!$zoneActive) {
        echo "注意：区域状态不是 active（NS 还没有切换到 Cloudflare），跳过解析验证\n";
    } elseif (!$wildcard) {
        echo "注意：非通配符名称会被解析缓存影响，跳过解析验证（用 '*.dnslb-verify.{$zoneInfo['name']}' 可以验证解析）\n";
    }
    $existing = $client->listRecords($zone, $domain);
    $foreign = array_filter($existing, static function (DnsRecord $r): bool {
        return strpos((string) $r->comment, CloudflareSync::COMMENT_PREFIX . 'verify-') !== 0;
    });
    if ($foreign !== []) {
        fwrite(STDERR, "{$domain} already has " . count($foreign) . " record(s) not created by this tool; choose an unused test name\n");
        exit(2);
    }
    if ($existing !== []) {
        echo "注意：{$domain} 下有 " . count($existing) . " 条上次中断留下的测试记录，将一并接管和清理\n";
    }

    echo "\n== 1. 发布：两个池的节点在同一名称下轮询（代理） ==\n";
    $tokyoA = $addNode('verify-tokyo-a', 'tokyo', '192.0.2.1', '2001:db8::1');
    $addNode('verify-tokyo-b', 'tokyo', '192.0.2.2', '2001:db8::2');
    $addNode('verify-frankfurt-a', 'frankfurt', '198.51.100.1', '2001:db8:1::1');
    if ($sync($app->dnsSync(), '创建 3 个节点的 A / AAAA 记录')) {
        $expected = [
            'A 192.0.2.1 proxied [dnslb node verify-tokyo-a]',
            'A 192.0.2.2 proxied [dnslb node verify-tokyo-b]',
            'A 198.51.100.1 proxied [dnslb node verify-frankfurt-a]',
            'AAAA 2001:db8:1::1 proxied [dnslb node verify-frankfurt-a]',
            'AAAA 2001:db8::1 proxied [dnslb node verify-tokyo-a]',
            'AAAA 2001:db8::2 proxied [dnslb node verify-tokyo-b]',
        ];
        sort($expected);
        $check($records() === $expected, '读回 6 条代理记录，备注标明节点');
        $check(!$app->dnsSync()->sync()->hasChanges(), '再次同步没有变更（读回的记录与期望一致）');
        $answers = $resolve('A', static function (array $answers): bool {
            return $answers !== [] && array_intersect($answers, ['192.0.2.1', '192.0.2.2', '198.51.100.1']) === [];
        });
        if ($answers !== null) {
            $check($answers !== [] && array_intersect($answers, ['192.0.2.1', '192.0.2.2', '198.51.100.1']) === [], '代理记录解析到 Cloudflare 边缘 IP，不暴露节点：' . implode(',', $answers));
        }
    }

    echo "\n== 2. 摘除不健康节点 ==\n";
    $tokyoA->v4->healthy = false;
    $tokyoA->v6->healthy = false;
    $app->nodes()->saveHealth($tokyoA);
    if ($sync($app->dnsSync(), '摘除 verify-tokyo-a')) {
        $check(count($records()) === 4 && !isset($app->dnsSync()->publishedNodeIds()['verify-tokyo-a']), '只剩 2 个健康节点的 4 条记录');
    }

    echo "\n== 3. 切换为仅 DNS（灰云），记录原地修改 ==\n";
    $dnsOnly = $makeApp($db, false);
    if ($sync($dnsOnly->dnsSync(), '修改代理状态和 TTL')) {
        $check(array_filter($records(), static function (string $r): bool {
            return strpos($r, ' ttl=60 ') === false;
        }) === [], '全部记录变为仅 DNS、TTL 60');
        $answers = $resolve('A', static function (array $answers): bool {
            return $answers === ['192.0.2.2', '198.51.100.1'];
        });
        if ($answers !== null) {
            $check($answers === ['192.0.2.2', '198.51.100.1'], '仅 DNS 时解析返回健康节点的地址：' . implode(',', $answers));
        }
    }

    $retireAll = static function () use ($app, $now): void {
        foreach ($app->nodes()->live() as $node) {
            $node->transition(Node::DRAINING, $now);
            $app->nodes()->save($node, $now);
        }
        $app->dnsSync()->sync();
    };

    echo "\n== 4. 接管现有 A 记录（先加后删） ==\n";
    $retireAll();
    $client->batch($zone, [], [], [new DnsRecord('A', $domain, FOREIGN_IP, true)]);
    $created[] = FOREIGN_IP;
    $addNode('verify-tokyo-c', 'tokyo', '192.0.2.3', '2001:db8::3');
    $blocked = $app->dnsSync()->sync();
    $check($blocked->takeoverPending && !$blocked->hasChanges(), '有其他记录时不发布节点，等待 takeover');
    $result = $app->dnsSync()->takeover();
    $check(true, 'takeover：' . implode('；', array_map(static function ($c): string {
        return $c->describe();
    }, $result->changes)));
    $check($records() === ['A 192.0.2.3 proxied [dnslb node verify-tokyo-c]', 'AAAA 2001:db8::3 proxied [dnslb node verify-tokyo-c]'], '旧记录已删除，只剩节点记录');
    $created = [];

    echo "\n== 5. 接管 CNAME（同一批次先删后增） ==\n";
    $retireAll();
    $client->batch($zone, [], [], [new DnsRecord('CNAME', $domain, FOREIGN_CNAME, true)]);
    $created[] = FOREIGN_CNAME;
    $addNode('verify-tokyo-d', 'tokyo', '192.0.2.4', '2001:db8::4');
    $app->dnsSync()->takeover();
    $check($records() === ['A 192.0.2.4 proxied [dnslb node verify-tokyo-d]', 'AAAA 2001:db8::4 proxied [dnslb node verify-tokyo-d]'], 'CNAME 已替换为节点记录');
    $created = [];
} catch (\Throwable $e) {
    $check(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    if (!isset($options['keep'])) {
        echo "\n== 清理 ==\n";
        try {
            $leftover = array_values(array_filter($client->listRecords($zone, $domain), static function (DnsRecord $r) use ($created): bool {
                return strpos((string) $r->comment, CloudflareSync::COMMENT_PREFIX . 'verify-') === 0 || in_array($r->content, $created, true);
            }));
            $client->batch($zone, $leftover, [], []);
            $check($client->listRecords($zone, $domain) === [], '已删除全部 ' . count($leftover) . ' 条临时记录');
        } catch (\Throwable $e) {
            $check(false, '清理失败，请手动删除 ' . $domain . ' 下的记录：' . $e->getMessage());
        }
    }
}

echo $failures === 0 ? "\n全部验证通过\n" : "\n{$failures} 项验证失败\n";
exit($failures === 0 ? 0 : 1);
