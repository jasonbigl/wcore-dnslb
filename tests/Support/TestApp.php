<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Provider\ProviderInterface;
use Wcore\Dnslb\Dnslb;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Node;

/**
 * 测试用的完整应用：内存数据库、模拟网络、可控时钟。
 */
final class TestApp
{
    public Application $app;
    public FakeNetwork $network;
    public FakeTransport $transport;
    public FakeClock $clock;
    public MemoryLogger $logger;
    public Config $config;

    /**
     * @param bool $cloudflare 使用 Cloudflare DNS（池不带 latency_region）
     */
    public function __construct(array $overrides = [], bool $cloudflare = false)
    {
        $this->network = new FakeNetwork();
        $this->transport = (new FakeTransport())->handle($this->network);
        $this->clock = new FakeClock(1700000000);
        $this->logger = new MemoryLogger();
        $this->config = self::config($overrides, $cloudflare);
        $this->app = new Application($this->config, 'test', false, [
            'transport' => $this->transport,
            'clock' => $this->clock,
            'logger' => $this->logger,
            'database' => Database::open(':memory:'),
        ]);
    }

    public static function config(array $overrides = [], bool $cloudflare = false): Config
    {
        $base = [
            'service' => 'web',
            'state' => ':memory:',
            'aws' => ['key' => 'AKID', 'secret' => 'SECRET'],
            'providers' => ['aws' => ['driver' => 'ec2']],
            'dns' => ['zone_id' => 'Z1', 'name' => 'app.example.com', 'ipv6' => true],
            'health_check' => [
                'canary_urls' => ['https://canary.test/'],
                'fall' => 2,
                'rise' => 2,
                'global_freeze_ratio' => 0.5,
                'pool_failover_after' => 180,
            ],
            'user_data' => ['template' => 'cloud-init.yaml'],
            'pools' => [
                'tokyo' => ['latency_region' => 'ap-northeast-1', 'max' => 5, 'launch' => [self::launch('ap-northeast-1')]],
                'frankfurt' => ['latency_region' => 'eu-central-1', 'max' => 5, 'launch' => [self::launch('eu-central-1')]],
            ],
        ];
        if ($cloudflare) {
            $base['dns'] = ['provider' => 'cloudflare', 'zone_id' => FakeCloudflare::ZONE_ID, 'name' => 'app.example.com', 'ipv6' => true];
            $base['cloudflare'] = ['api_token' => FakeCloudflare::TOKEN];
            foreach ($base['pools'] as $name => $pool) {
                unset($base['pools'][$name]['latency_region']);
            }
        }
        return Config::fromArray(array_replace_recursive($base, $overrides), (string) realpath(__DIR__ . '/../fixtures'));
    }

    /**
     * 换一份配置重建应用（相当于改配置后重新部署），沿用同一个数据库、模拟网络、时钟和日志。
     */
    public function reconfigure(array $overrides, bool $cloudflare = false): void
    {
        $this->config = self::config($overrides, $cloudflare);
        $this->app = new Application($this->config, 'test', false, [
            'transport' => $this->transport,
            'clock' => $this->clock,
            'logger' => $this->logger,
            'database' => $this->app->database(),
        ]);
    }

    /**
     * 使用同一套模拟环境（数据库、网络、时钟、日志）的 Dnslb 入口。
     *
     * @param array<string,ProviderInterface> $providers
     */
    public function lb(array $providers = []): Dnslb
    {
        return new Dnslb($this->config, [
            'transport' => $this->transport,
            'clock' => $this->clock,
            'logger' => $this->logger,
            'database' => $this->app->database(),
            'providers' => $providers,
        ]);
    }

    /**
     * 直接写入一个节点（跳过建机流程）。
     */
    public function addNode(string $nodeId, string $pool, string $state, ?string $ipv4, ?string $ipv6 = null, bool $healthy = true): Node
    {
        $now = $this->clock->now();
        $node = new Node($nodeId, $pool, 'aws', 'x', 0, 10, $now);
        $node->transition($state, $now);
        $node->ipv4 = $ipv4;
        $node->ipv6 = $ipv6;
        foreach ([$node->v4, $node->v6] as $health) {
            $health->healthy = $healthy;
            $health->since = $now;
        }
        $this->app->nodes()->insert($node, $now);
        $this->app->nodes()->saveHealth($node);
        return $node;
    }

    public function node(string $nodeId): Node
    {
        $node = $this->app->nodes()->find($nodeId);
        if ($node === null) {
            throw new \LogicException("Node {$nodeId} not found");
        }
        return $node;
    }

    /**
     * @return string[] Route 53 上某个名称下记录的简要描述，如 "A web-tokyo-a 1.1.1.1"
     */
    public function records(string $name): array
    {
        $result = [];
        foreach ($this->network->route53->recordsNamed($name) as $record) {
            $value = $record->aliasTarget !== null ? '-> ' . $record->aliasTarget->dnsName : implode(',', $record->values);
            $result[] = "{$record->type} {$record->setIdentifier} {$value}";
        }
        sort($result);
        return $result;
    }

    /**
     * @return string[] 事件类型列表（按时间先后）
     */
    public function eventTypes(): array
    {
        return array_reverse(array_map(static function ($event) {
            return $event->type;
        }, $this->app->events()->recent(1000)));
    }

    private static function launch(string $region): array
    {
        return [
            'provider' => 'aws',
            'region' => $region,
            'instance_type' => 't3.micro',
            'ami' => 'ami-0abc',
            'subnet_id' => 'subnet-0abc',
        ];
    }
}
