<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Config\ProviderConfig;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\State\Node;

/**
 * 校验配置，并用只读接口测试各外部服务的凭证、连通性和建机参数。
 */
final class Checker
{
    /** EC2 user-data 上限（Linode 更宽松） */
    private const USER_DATA_LIMIT = 16384;

    private Application $app;
    private CheckReport $report;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function run(): CheckReport
    {
        $this->report = new CheckReport();
        $config = $this->app->config();

        $this->report->add(CheckItem::OK, '配置', sprintf('服务 %s，%d 个池（%s）', $config->service, count($config->pools), implode(', ', array_keys($config->pools))));

        $this->attempt('状态库', function () use ($config): string {
            return "{$config->statePath}（表结构版本 {$this->app->database()->schemaVersion()}）";
        });

        $this->attempt('user-data 模板', function () use ($config): string {
            $pool = reset($config->pools);
            $rendered = $this->app->userDataRenderer()->render([
                'node_id' => "{$config->service}-{$pool->name}-000000",
                'service' => $config->service,
                'pool' => $pool->name,
                'provider' => $pool->launch[0]->provider,
                'region' => $pool->launch[0]->region,
                'latency_region' => $pool->latencyRegion ?? '',
            ]);
            if (strlen($rendered) > self::USER_DATA_LIMIT) {
                throw new \RuntimeException(sprintf('渲染后 %d 字节，超过 EC2 的 16 KB 上限', strlen($rendered)));
            }
            return sprintf('%s（渲染后 %d 字节）', $config->userData->template, strlen($rendered));
        });

        $this->checkDns();
        $this->checkProviders();
        $this->checkServiceName();
        $this->checkMetrics();
        $this->checkNotify();

        $families = $config->dns->ipv6 ? [Node::FAMILY_V4, Node::FAMILY_V6] : [Node::FAMILY_V4];
        $round = $this->app->prober()->run([], $families);
        foreach ($families as $family) {
            $label = $family === Node::FAMILY_V6 ? 'IPv6' : 'IPv4';
            if ($round->canaryOk[$family]) {
                $this->report->add(CheckItem::OK, "探测机 {$label} 出口", '可以访问金丝雀地址');
            } else {
                $this->report->add(CheckItem::FAIL, "探测机 {$label} 出口", '无法访问任何金丝雀地址' . ($family === Node::FAMILY_V6 ? '（dns.ipv6 已开启，探测机必须有 IPv6）' : ''));
            }
        }
        return $this->report;
    }

    private function checkDns(): void
    {
        foreach ($this->app->dnsSync()->check() as $item) {
            $this->report->items[] = $item;
        }
    }

    private function checkProviders(): void
    {
        $config = $this->app->config();
        $providers = $this->app->providers();
        foreach ($config->providers as $name => $provider) {
            if ($provider->driver === ProviderConfig::DRIVER_LINODE) {
                $this->attempt("Linode 账号 {$name}", function () use ($providers, $name): string {
                    $profile = $providers->linodeClient($name)->request('GET', '/profile');
                    return '用户 ' . ($profile['username'] ?? '?');
                });
            } else {
                $this->attempt("EC2 账号 {$name}", function () use ($providers, $name, $config): string {
                    $regions = $providers->regionsByProvider()[$name] ?? [];
                    $count = count($providers->get($name)->listManaged($config->service, $regions));
                    return "可以查询实例（当前 {$count} 台属于本服务）";
                });
            }
        }

        foreach ($config->pools as $pool) {
            foreach ($pool->launch as $spec) {
                $label = "池 {$pool->name} 建机方式 " . ($spec->index + 1) . "（{$spec->provider}/{$spec->region}）";
                $this->attempt($label, function () use ($spec): string {
                    return $spec->driver === ProviderConfig::DRIVER_LINODE ? $this->checkLinodeSpec($spec) : $this->checkEc2Spec($spec);
                });
            }
        }
    }

    /**
     * 云上带本服务标签、但状态库和本服务的 DNS 记录里都没有的实例：可能是孤儿，也可能是同一云账号里另一套部署用了相同的服务名
     * （标签相同，孤儿清理和 rebuildState() 会把它的机器当成本服务的删除）。
     */
    private function checkServiceName(): void
    {
        $config = $this->app->config();
        $name = '服务名冲突';
        $known = [];
        foreach ($this->app->nodes()->live() as $node) {
            $known[$node->nodeId] = true;
            if ($node->instanceId !== null) {
                $known["{$node->provider}|{$node->instanceId}"] = true;
            }
        }
        try {
            $known += $this->app->dnsSync()->publishedNodeIds();
        } catch (\Throwable $e) {
            $this->report->add(CheckItem::WARN, $name, '读不到 DNS 记录，无法检查：' . $e->getMessage());
            return;
        }

        $unknown = [];
        foreach ($this->app->providers()->regionsByProvider() as $provider => $regions) {
            try {
                $instances = $this->app->providers()->get($provider)->listManaged($config->service, $regions);
            } catch (\Throwable $e) {
                $this->report->add(CheckItem::WARN, $name, "无法列出 {$provider} 的实例：{$e->getMessage()}");
                continue;
            }
            foreach ($instances as $instance) {
                if ($instance->status === Instance::GONE || isset($known["{$provider}|{$instance->id}"]) || isset($known[(string) $instance->nodeId])) {
                    continue;
                }
                $unknown[] = "{$provider}/{$instance->region} {$instance->id}" . ($instance->nodeId !== null ? "（{$instance->nodeId}）" : '');
            }
        }
        if ($unknown === []) {
            $this->report->add(CheckItem::OK, $name, "带 dnslb:service={$config->service} 标签的实例都属于本服务");
            return;
        }
        $this->report->add(CheckItem::WARN, $name, sprintf(
            '%d 台带 dnslb:service=%s 标签的实例既不在状态库、也不在 %s 的 DNS 记录中：%s。'
            . '如果是同一云账号里另一套部署使用了相同的服务名，请给本服务换一个名字，否则孤儿清理和 rebuildState() 会把它们当成本服务的机器删除；'
            . '如果是本服务遗留的孤儿，用 Dnslb::cleanupOrphans() 预览后删除',
            count($unknown),
            $config->service,
            $config->dns->name,
            implode('、', array_slice($unknown, 0, 5)) . (count($unknown) > 5 ? ' 等' : '')
        ));
    }

    private function checkMetrics(): void
    {
        $config = $this->app->config();
        $queries = [];
        foreach ($config->pools as $pool) {
            foreach ($pool->scaling->metrics as $rule) {
                $queries["{$rule->source}\0{$rule->query}"] = ["扩缩容指标 {$rule->name}", $rule->source, $rule->query];
            }
        }
        if ($config->drain->idleQuery !== null && $config->drain->idleSource !== null) {
            $queries["{$config->drain->idleSource}\0{$config->drain->idleQuery}"] = ['排空空闲指标', $config->drain->idleSource, $config->drain->idleQuery];
        }
        foreach ($queries as [$label, $source, $query]) {
            $this->attempt("{$label}（{$source}）", function () use ($source, $query): string {
                $values = $this->app->metrics()->get($source)->queryByNode($query);
                return $values === [] ? '查询成功，但目前没有任何节点的数据（节点上线后才会有）' : '查询成功，返回 ' . count($values) . ' 个节点的数据';
            });
        }
    }

    private function checkNotify(): void
    {
        $notify = $this->app->config()->notify;
        if ($notify === null) {
            $this->report->add(CheckItem::WARN, '邮件通知', '未配置 notify，不会发送邮件通知');
            return;
        }
        $name = "SES（{$notify->region}）";
        try {
            $account = $this->app->ses()->getAccount($notify->region);
        } catch (\Throwable $e) {
            $this->report->add(CheckItem::FAIL, $name, $e->getMessage());
            return;
        }
        if (empty($account['SendingEnabled'])) {
            $this->report->add(CheckItem::FAIL, $name, '账号已禁止发送邮件');
        } elseif (empty($account['ProductionAccessEnabled'])) {
            $this->report->add(CheckItem::WARN, $name, "账号仍在沙箱中，只能发给已验证的地址（{$notify->from} → " . implode(', ', $notify->to) . '）');
        } else {
            $this->report->add(CheckItem::OK, $name, '可以发送邮件');
        }
    }

    private function checkLinodeSpec(LaunchSpec $spec): string
    {
        $client = $this->app->providers()->linodeClient($spec->provider);
        $region = $client->request('GET', '/regions/' . rawurlencode($spec->region));
        if (!in_array('Metadata', $region['capabilities'] ?? [], true)) {
            throw new \RuntimeException("机房 {$spec->region} 不支持 Metadata 服务，无法使用 user-data");
        }
        $type = $client->request('GET', '/linode/types/' . rawurlencode($spec->options['type']));
        $image = $client->request('GET', '/images/' . rawurlencode($spec->options['image']));
        if (!in_array('cloud-init', $image['capabilities'] ?? [], true)) {
            throw new \RuntimeException("镜像 {$spec->options['image']} 不支持 cloud-init，无法使用 user-data");
        }
        return sprintf('%s，%s，%s', $region['label'] ?? $spec->region, $type['label'] ?? $spec->options['type'], $image['label'] ?? $spec->options['image']);
    }

    private function checkEc2Spec(LaunchSpec $spec): string
    {
        $client = $this->app->providers()->ec2Client();
        $image = $client->describeImage($spec->region, $spec->options['ami']);
        if ($image === null) {
            throw new \RuntimeException("AMI {$spec->options['ami']} 不存在或无权使用");
        }
        if ($image['state'] !== 'available') {
            throw new \RuntimeException("AMI {$spec->options['ami']} 状态为 {$image['state']}");
        }
        $subnet = $client->describeSubnet($spec->region, $spec->options['subnet_id']);
        if ($subnet === null) {
            throw new \RuntimeException("子网 {$spec->options['subnet_id']} 不存在");
        }
        if ($this->app->config()->dns->ipv6 && !$subnet['ipv6']) {
            throw new \RuntimeException("子网 {$spec->options['subnet_id']} 没有 IPv6 网段，但 dns.ipv6 已开启");
        }
        return "AMI {$image['name']}，子网 {$subnet['id']}（{$subnet['az']}）";
    }

    private function attempt(string $name, callable $check): void
    {
        try {
            $this->report->add(CheckItem::OK, $name, $check());
        } catch (\Throwable $e) {
            $this->report->add(CheckItem::FAIL, $name, $e->getMessage());
        }
    }
}
