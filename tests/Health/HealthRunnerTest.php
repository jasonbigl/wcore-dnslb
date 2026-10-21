<?php

namespace Wcore\Dnslb\Tests\Health;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * health 命令的端到端流程：探测 → 更新健康状态 → 同步 DNS。
 */
final class HealthRunnerTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = new TestApp(['dns' => ['ipv6' => false], 'health_check' => ['interval' => 10]]);
    }

    protected function tearDown(): void
    {
        $this->t->app->lock('health')->release();
        $this->t->app->lock('dns')->release();
    }

    public function testRunsRoundsForDurationAndSyncsOnlyWhenDesiredStateChanges(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $this->t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');
        $route53 = $this->t->network->route53;

        // 按时钟变化计算轮次；第 3 轮起 web-tokyo-a 故障（fall=2，第 4 轮被摘除）
        $round = 0;
        $lastNow = null;
        $clock = $this->t->clock;
        $network = $this->t->network;
        $this->t->transport->handle(function (Request $request) use (&$round, &$lastNow, $clock, $network) {
            if ($lastNow !== $clock->now()) {
                $lastNow = $clock->now();
                $round++;
            }
            if ($round >= 3 && $request->resolve !== [] && strpos($request->resolve[0], ':10.0.0.1') !== false) {
                return new Response(503);
            }
            return $network($request);
        });
        $start = $clock->now();

        $completed = $this->t->app->healthRunner()->run(55, false, false);

        self::assertSame(6, $completed, 'rounds at 0, 10, 20, 30, 40, 50 seconds');
        self::assertSame($start + 50, $clock->now(), 'exits after the last round instead of sleeping past the duration');
        self::assertCount(2, $route53->batches, 'initial publish + one removal');
        self::assertSame(['A web-tokyo-b 10.0.0.2'], $this->t->records('tokyo.pool.app.example.com'));
        // 每次同步读取 1 个 latency 名称 + 2 个池名称；只有首轮和发生变化的那一轮会同步
        self::assertSame(6, $route53->listCalls);
        self::assertSame(['node_unhealthy'], $this->t->eventTypes());
    }

    public function testOnceRunsSingleRound(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $completed = $this->t->app->healthRunner()->run(55, true, false);

        self::assertSame(1, $completed);
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $this->t->records('tokyo.pool.app.example.com'));
    }

    public function testDryRunDoesNotChangeDns(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $this->t->app->healthRunner()->run(55, true, true);

        self::assertSame([], $this->t->network->route53->batches);
        self::assertNotEmpty($this->t->logger->matching('[dry-run] DNS UPSERT'));
    }

    public function testRoute53FailureIsRetriedNextRound(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $calls = 0;
        $network = $this->t->network;
        $this->t->transport->handle(function (Request $request) use (&$calls, $network) {
            if ($request->method === 'POST' && $calls++ === 0) {
                return new Response(400, [], '<ErrorResponse><Error><Code>AccessDenied</Code><Message>nope</Message></Error></ErrorResponse>');
            }
            return $network($request);
        });

        $completed = $this->t->app->healthRunner()->run(15, false, false);

        self::assertSame(1, $completed, 'first round failed, second succeeded');
        self::assertNotEmpty($this->t->logger->matching('Health round failed: AccessDenied'));
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $this->t->records('tokyo.pool.app.example.com'));
    }

    public function testExitsWhenAnotherProcessHoldsTheLock(): void
    {
        $other = new FileLock(sys_get_temp_dir() . '/dnslb-web.health.lock');
        self::assertTrue($other->acquire());
        try {
            $completed = $this->t->app->healthRunner()->run(55, false, false);
        } finally {
            $other->release();
        }

        self::assertSame(0, $completed);
        self::assertNotEmpty($this->t->logger->matching('Another health process is still running'));
        self::assertGreaterThanOrEqual(15, count($this->t->clock->sleeps), 'waits up to 15 seconds for the lock');
    }
}
