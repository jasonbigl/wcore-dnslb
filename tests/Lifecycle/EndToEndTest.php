<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeNetwork;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\FakeTransport;
use Wcore\Dnslb\Tests\Support\MemoryLogger;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * tick 与 health 配合的完整流程：建机 → 启动 → 预热 → 上线 → 发布 DNS。
 */
final class EndToEndTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dnslb-e2e-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob("{$this->dir}/*") ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testFromEmptyToServingTraffic(): void
    {
        [$app, $aws, $network, $clock] = $this->app();

        $app->tickRunner()->run(false);
        $nodes = $app->nodes()->live();
        self::assertCount(2, $nodes, 'one node per pool (min=1)');

        foreach ($nodes as $i => $node) {
            $aws->boot($node->nodeId, "10.0.{$i}.1", "2001:db8:{$i}::1");
        }
        $clock->advance(60);
        $app->tickRunner()->run(false);
        self::assertSame([Node::WARMING, Node::WARMING], self::states($app));
        self::assertSame([], $network->route53->batches, 'nothing is published before nodes pass health checks');

        $app->healthRunner()->run(20, false, false);
        $clock->advance(60);
        $app->tickRunner()->run(false);

        self::assertSame([Node::IN_SERVICE, Node::IN_SERVICE], self::states($app));
        $latency = $network->route53->recordsNamed('app.example.com');
        self::assertCount(4, $latency, 'A + AAAA latency record for each of the two pools');
        self::assertCount(2, $network->route53->recordsNamed('tokyo.pool.app.example.com'));
        self::assertCount(2, $network->route53->recordsNamed('frankfurt.pool.app.example.com'));
    }

    public function testDryRunTickChangesNothing(): void
    {
        [$app, $aws, $network] = $this->app();
        $app->tickRunner()->run(false);
        // 让演练看到一个低于 min 的池：把 tokyo 的节点标记为已销毁
        $tokyo = $app->nodes()->liveInPool('tokyo')[0];
        $tokyo->transition(Node::TERMINATED, time());
        $app->nodes()->save($tokyo, time());
        $before = $this->snapshot();

        [$dry, $dryAws, , , $logger] = $this->app(true, $network);
        $dryAws->instances = $aws->instances;

        $dry->tickRunner()->run(true);

        self::assertSame($before, $this->snapshot(), 'state database is untouched');
        self::assertSame([], $dryAws->callsMatching('create'));
        self::assertNotEmpty($logger->matching('[dry-run] would create instance web-tokyo-'));
        self::assertSame([], $network->route53->batches);
    }

    /**
     * @return array{0:Application,1:FakeProvider,2:FakeNetwork,3:FakeClock,4:MemoryLogger}
     */
    private function app(bool $dryRun = false, ?FakeNetwork $network = null): array
    {
        $config = TestApp::config(['state' => "{$this->dir}/state.sqlite", 'health_check' => ['interval' => 10]]);
        $network = $network ?? new FakeNetwork();
        $clock = new FakeClock();
        $logger = new MemoryLogger();
        $app = new Application($config, 'test', $dryRun, [
            'transport' => (new FakeTransport())->handle($network),
            'clock' => $clock,
            'logger' => $logger,
        ]);
        $aws = new FakeProvider('aws');
        $app->providers()->set('aws', $aws);
        return [$app, $aws, $network, $clock, $logger];
    }

    private function snapshot(): array
    {
        $db = Database::open("{$this->dir}/state.sqlite");
        return [$db->fetchAll('SELECT * FROM nodes ORDER BY node_id'), $db->fetchAll('SELECT * FROM pools ORDER BY pool')];
    }

    /**
     * @return string[]
     */
    private static function states(Application $app): array
    {
        return array_map(static function (Node $node): string {
            return $node->state;
        }, $app->nodes()->live());
    }
}
