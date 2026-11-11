<?php

/**
 * 在真实 EC2 上验证弹性 IP 流程（launch 的 elastic_ip）：申请 → 建机 → 关联 → 删除实例 → 释放，结束时确认没有残留。
 * 会创建一台真实的实例和一个弹性 IP，几分钟内删除（按秒 / 小时计费，费用很低）。
 *
 * 用法：
 *   php tools/verify-ec2-eip.php --region <区域> --ami <AMI> --subnet <子网> [--sg <安全组>] [--type t3.micro] [--service dnslb-verify]
 *
 * 密钥读取环境变量 AWS_KEY、AWS_SECRET，未设置时读取仓库根目录的 .env。
 *
 * --service 是写入 dnslb:service 标签的服务名，默认 dnslb-verify，不会和正式服务混在一起。使用 docs/iam-policy.json
 * 的受限密钥时，要传策略里的 SERVICE 才有权限（这样也顺便验证了策略）；此时不要在 tick 正在运行的服务名上测试超过 15 分钟，
 * 否则测试机会被当成孤儿实例告警。
 * 子网必须能通过互联网网关出网（公有子网）；区域内至少要剩 1 个弹性 IP 配额。
 * 需要的 IAM 权限：docs/iam-policy.json 中 EC2 的全部语句。
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/env.php';

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Provider\Ec2Provider;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\ProviderInterface;
use Wcore\Dnslb\Support\StreamLogger;

$options = getopt('', ['region:', 'ami:', 'subnet:', 'sg:', 'type:', 'service:']);
$region = $options['region'] ?? null;
$ami = $options['ami'] ?? null;
$subnet = $options['subnet'] ?? null;
$service = $options['service'] ?? 'dnslb-verify';
$key = dnslb_env('AWS_KEY');
$secret = dnslb_env('AWS_SECRET');
if ($region === null || $ami === null || $subnet === null || !$key || !$secret) {
    fwrite(STDERR, "Usage: php tools/verify-ec2-eip.php --region <region> --ami <ami-id> --subnet <subnet-id> [--sg <sg-id>] [--type t3.micro] [--service dnslb-verify]\n");
    if (!$key || !$secret) {
        fwrite(STDERR, "Missing AWS_KEY / AWS_SECRET (environment variables or .env in the repository root)\n");
    }
    exit(2);
}

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
};

$launch = [
    'provider' => 'aws',
    'region' => $region,
    'instance_type' => $options['type'] ?? 't3.micro',
    'ami' => $ami,
    'subnet_id' => $subnet,
    'elastic_ip' => true,
];
if (isset($options['sg'])) {
    $launch['security_group_ids'] = array_values((array) $options['sg']);
}
// DNS 不会被访问，这里只是为了通过配置校验
$config = Config::fromArray([
    'service' => $service,
    'state' => ':memory:',
    'aws' => ['key' => $key, 'secret' => $secret],
    'providers' => ['aws' => ['driver' => 'ec2']],
    'dns' => ['zone_id' => 'Z0000000000000', 'name' => 'dnslb-verify.invalid', 'ipv6' => false],
    'user_data' => ['template' => 'cloud-init.yaml', 'vars' => [
        'cert_center_url' => 'https://example.invalid',
        'grafana_remote_write_url' => 'https://example.invalid',
        'grafana_username' => 'unused',
        'grafana_write_token' => 'unused',
    ]],
    'pools' => ['verify' => ['latency_region' => $region, 'max' => 1, 'launch' => [$launch]]],
], (string) realpath(__DIR__ . '/../examples'));
$app = new Application($config, 'verify', false, ['logger' => new StreamLogger(null, 'info', 'verify')]);
/** @var Ec2Provider $provider */
$provider = $app->providers()->get('aws');
$spec = $config->pools['verify']->launch[0];
$nodeId = "{$service}-verify-" . substr(bin2hex(random_bytes(4)), 0, 6);
$tags = [ProviderInterface::TAG_SERVICE => $service, ProviderInterface::TAG_POOL => 'verify'];

/** @return \Wcore\Dnslb\Provider\Address[] 本次测试节点名下的弹性 IP */
$addresses = static function () use ($provider, $service, $region, $nodeId): array {
    return array_values(array_filter($provider->listAddresses($service, [$region]), static function ($address) use ($nodeId): bool {
        return $address->nodeId === $nodeId;
    }));
};

echo "Node {$nodeId}: {$launch['instance_type']} in {$region}, tagged dnslb:service={$service}\n";
$instance = null;
try {
    // 1. 申请弹性 IP → 建机 → 等 running → 关联
    $started = microtime(true);
    $instance = $provider->create($spec, $nodeId, "#cloud-config\n", $tags);
    $took = round(microtime(true) - $started, 1);
    $check($instance->elasticIp, "建机：实例 {$instance->id}，带弹性 IP 标记");
    $check($instance->ipv4 !== null && $instance->status === Instance::RUNNING, "建机时已关联弹性 IP（{$instance->ipv4}），从发出请求到关联完成 {$took} 秒");

    // 2. tick 的补绑路径：已关联时直接返回同一个地址；没关联上（上一步失败）时在这里关联
    $attached = null;
    for ($i = 0; $i < 12 && $attached === null; $i++) {
        try {
            $attached = $provider->attachAddress($region, $nodeId, $instance->id);
        } catch (\Throwable $e) {
            echo "       还不能关联：{$e->getMessage()}\n";
            sleep(5);
        }
    }
    $check($attached !== null && ($instance->ipv4 === null || $attached === $instance->ipv4), "attachAddress 可重复调用，返回 {$attached}");

    // 3. 实例的公网 IPv4 就是弹性 IP，没有另外的自动公网 IP
    $seen = null;
    for ($i = 0; $i < 10; $i++) {
        $seen = $provider->get($region, $instance->id);
        if ($seen !== null && $seen->ipv4 !== null) {
            break;
        }
        sleep(2);
    }
    $check($seen !== null && $seen->ipv4 === $attached, 'DescribeInstances 看到的公网 IPv4 是弹性 IP：' . ($seen === null ? '查不到实例' : (string) $seen->ipv4));
    $check($seen !== null && $seen->elasticIp, '实例带 dnslb:eip 标签（rebuildState 据此恢复标记）');
    $own = $addresses();
    $check(count($own) === 1 && $own[0]->instanceId === $instance->id, '弹性 IP 带节点标签并关联到这台实例：' . implode(', ', array_map(static function ($a): string {
        return "{$a->id} {$a->ip} -> " . ($a->instanceId ?? '未关联');
    }, $own)));

    // 4. 没有 $detach 时不会释放仍在使用的地址
    if ($own !== []) {
        try {
            $provider->releaseAddress($region, $own[0]->id, false);
            $check(false, 'releaseAddress(detach=false) 应当拒绝释放仍关联着实例的地址');
        } catch (\Throwable $e) {
            $check(count($addresses()) === 1, 'releaseAddress(detach=false) 拒绝释放仍关联着实例的地址');
        }
    }

    // 5. 删除实例后立即释放（Reconciler::terminate 的顺序）：实例还在 shutting-down 时能否解除关联并释放
    $provider->terminate($region, $instance->id);
    $started = microtime(true);
    $attempts = 0;
    $released = false;
    while (!$released && microtime(true) - $started < 180) {
        $attempts++;
        try {
            $provider->releaseAddresses($region, $nodeId);
            $released = true;
        } catch (\Throwable $e) {
            echo "       第 {$attempts} 次释放失败：{$e->getMessage()}\n";
            sleep(10);
        }
    }
    $check($released, "删除实例后释放弹性 IP：第 {$attempts} 次成功，用时 " . round(microtime(true) - $started, 1) . ' 秒'
        . ($attempts > 1 ? '（tick 中会保持 TERMINATING 并重试）' : ''));
    $instance = null;

    // 6. 没有残留
    sleep(3);
    $check($addresses() === [], '节点名下已没有弹性 IP');
    $left = $provider->findByNodeId($region, $nodeId);
    $check($left === null || $left->status === Instance::GONE, '实例已删除');
} catch (\Throwable $e) {
    $check(false, get_class($e) . ': ' . $e->getMessage());
} finally {
    // 无论如何都清理：实例和弹性 IP 都在计费
    try {
        $found = $instance ?? $provider->findByNodeId($region, $nodeId);
        if ($found !== null && $found->status !== Instance::GONE) {
            echo "Cleaning up instance {$found->id}\n";
            $provider->terminate($region, $found->id);
        }
        for ($i = 0; $i < 18; $i++) {
            try {
                $provider->releaseAddresses($region, $nodeId);
                break;
            } catch (\Throwable $e) {
                sleep(10);
            }
        }
        $leftover = $addresses();
        if ($leftover !== []) {
            fwrite(STDERR, "LEFTOVER elastic IPs, release them by hand: " . implode(', ', array_map(static function ($a): string {
                return "{$a->id} ({$a->ip})";
            }, $leftover)) . "\n");
            $failures++;
        }
    } catch (\Throwable $e) {
        fwrite(STDERR, "Cleanup failed, check instance and elastic IPs tagged dnslb:node={$nodeId} in {$region}: {$e->getMessage()}\n");
        $failures++;
    }
}

echo $failures === 0 ? "All checks passed.\n" : "{$failures} check(s) failed.\n";
exit($failures === 0 ? 0 : 1);
