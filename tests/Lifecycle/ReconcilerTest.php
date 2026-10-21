<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Lifecycle\Reconciler;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

final class ReconcilerTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;
    private FakeProvider $backup;

    protected function setUp(): void
    {
        $this->t = $this->app();
    }

    public function testBootstrapCreatesMinNodesAndPromotesThemThroughLifecycle(): void
    {
        $this->tick();

        $nodes = $this->t->app->nodes()->liveInPool('tokyo');
        self::assertCount(2, $nodes);
        foreach ($nodes as $node) {
            self::assertSame(Node::BOOTING, $node->state);
            self::assertMatchesRegularExpression('/^web-tokyo-[a-z0-9]{6}$/', $node->nodeId);
            self::assertNotNull($node->instanceId);
        }
        self::assertCount(2, $this->aws->callsMatching('create'));
        $userData = $this->aws->userData[$nodes[0]->nodeId];
        self::assertStringContainsString("node_id={$nodes[0]->nodeId}", $userData);
        self::assertStringContainsString('latency_region=ap-northeast-1', $userData);
        $tags = $this->aws->instanceOf($nodes[0]->nodeId)->tags;
        self::assertSame('web', $tags['dnslb:service']);
        self::assertSame('tokyo', $tags['dnslb:pool']);

        // 启动完成并分配地址 → WARMING
        $id = $nodes[0]->nodeId;
        $this->aws->boot($id, '10.0.0.1', '2001:db8::1');
        $this->tick();
        self::assertSame(Node::WARMING, $this->node($id)->state);
        self::assertSame('2001:db8::1', $this->node($id)->ipv6);
        self::assertSame(Node::BOOTING, $this->node($nodes[1]->nodeId)->state);
        self::assertCount(2, $this->aws->callsMatching('create'), 'pending nodes count toward min');

        // 健康检查通过 → IN_SERVICE
        $this->markHealthy($id);
        $this->tick();
        self::assertSame(Node::IN_SERVICE, $this->node($id)->state);
        self::assertContains('node_in_service', $this->t->eventTypes());
    }

    public function testWaitsForIpv6WhenEnabled(): void
    {
        $this->tick();
        $id = $this->t->app->nodes()->liveInPool('tokyo')[0]->nodeId;
        $this->aws->boot($id, '10.0.0.1', null);

        $this->tick();

        self::assertSame(Node::BOOTING, $this->node($id)->state);
        self::assertSame('10.0.0.1', $this->node($id)->ipv4);
    }

    public function testFallsBackToNextLaunchSpec(): void
    {
        $this->aws->failCreate = 'InsufficientInstanceCapacity: no capacity';

        $this->tick();

        $nodes = $this->t->app->nodes()->liveInPool('tokyo');
        self::assertCount(2, $nodes);
        foreach ($nodes as $node) {
            self::assertSame('backup', $node->provider);
            self::assertSame(1, $node->launchIndex);
            self::assertSame(20, $node->weight);
        }
        self::assertCount(2, $this->backup->callsMatching('create'));
        $event = $this->t->app->events()->recent(1)[0];
        self::assertStringContainsString('前面的方式失败', $event->message);
    }

    public function testLaunchFailuresPauseThePool(): void
    {
        $this->aws->failCreate = 'VcpuLimitExceeded';
        $this->backup->failCreate = 'quota';

        for ($i = 0; $i < 3; $i++) {
            $this->tick();
        }

        self::assertCount(3, $this->aws->callsMatching('create'), 'one failing attempt per tick, not min-many');
        self::assertTrue($this->t->app->pools()->get('tokyo')->isPaused($this->t->clock->now()));
        self::assertContains('pool_paused', $this->t->eventTypes());

        $this->tick();
        self::assertCount(3, $this->aws->callsMatching('create'), 'paused pool does not create nodes');
        // 没建出实例的节点在宽限期内保持 TERMINATING，确认没有实例后才结束
        self::assertSame([Node::TERMINATING], array_values(array_unique(array_map(static function (Node $n): string {
            return $n->state;
        }, $this->t->app->nodes()->liveInPool('tokyo')))));
    }

    public function testUncertainCreateIsResolvedByLookingUpTheNode(): void
    {
        $this->aws->timeoutCreate = true;
        $this->tick();

        $nodes = $this->t->app->nodes()->liveInPool('tokyo');
        self::assertCount(2, $nodes);
        self::assertSame(Node::PROVISIONING, $nodes[0]->state);
        self::assertNull($nodes[0]->instanceId);
        self::assertSame([], $this->backup->calls, 'must not fall back while the first request may have succeeded');

        $this->aws->timeoutCreate = false;
        $this->tick();

        $node = $this->node($nodes[0]->nodeId);
        self::assertSame(Node::BOOTING, $node->state);
        self::assertSame($this->aws->instanceOf($node->nodeId)->id, $node->instanceId);
        self::assertCount(2, $this->aws->callsMatching('create'), 'adopted instead of creating again');
    }

    public function testInterruptedFallbackLaunchIsFoundWhereItWasCreated(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 1]]]);
        $this->aws->failCreate = 'InsufficientInstanceCapacity';
        $this->backup->crashAfterCreate = true;
        $this->tick();

        [$node] = $this->t->app->nodes()->liveInPool('tokyo');
        self::assertSame(Node::PROVISIONING, $node->state);
        self::assertSame('backup', $node->provider);
        self::assertSame(1, $node->launchIndex);

        // 首选方式已恢复，但实例已在备用方式上创建，不能再建一台
        $this->aws->failCreate = null;
        $this->tick();

        $node = $this->node($node->nodeId);
        self::assertSame(Node::BOOTING, $node->state);
        self::assertSame($this->backup->instanceOf($node->nodeId)->id, $node->instanceId);
        self::assertCount(1, $this->aws->callsMatching('create'));
        self::assertCount(1, $this->backup->callsMatching('create'));
    }

    public function testUnknownLaunchOutcomeFallsBackOnlyAfterTwoMinutes(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 1]]]);
        $this->aws->unknownCreate = 'RequestLimitExceeded after a timeout';

        // 第 0、60 秒：结果未知，在同一方式上重发，不换方式
        for ($i = 0; $i < 2; $i++) {
            $this->tick();
        }
        [$node] = $this->t->app->nodes()->liveInPool('tokyo');
        self::assertSame(Node::PROVISIONING, $node->state);
        self::assertSame('aws', $node->provider);
        self::assertStringContainsString('outcome unknown', (string) $node->lastError);
        self::assertSame([], $this->backup->calls, 'no fallback while the first request may have succeeded');
        self::assertCount(2, $this->aws->callsMatching('create'));

        // 第 120 秒：仍查不到实例，换下一种方式
        $this->tick();
        $node = $this->node($node->nodeId);
        self::assertSame(Node::BOOTING, $node->state);
        self::assertSame('backup', $node->provider);
        self::assertCount(2, $this->aws->callsMatching('create'));
        self::assertCount(1, $this->backup->callsMatching('create'));
        self::assertNotEmpty($this->t->logger->matching('still unknown after 120s'));
    }

    public function testInstanceThatShowsUpLateIsStillTerminated(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 0]]]);
        // 建机结果未知、还没有实例 ID 的节点被取消
        $node = $this->t->addNode('web-tokyo-late', 'tokyo', Node::PROVISIONING, null, null, false);
        $this->t->app->reconciler()->cancel($node, 'test');
        self::assertSame(Node::TERMINATING, $this->node('web-tokyo-late')->state);

        // 两分钟后实例才能查到（EC2 最终一致性）
        $this->t->clock->advance(120);
        $instance = new Instance('aws', 'i-late', 'ap-northeast-1', Instance::RUNNING, '10.0.0.7', null, 'web-tokyo-late');
        $this->aws->instances[$instance->id] = $instance;
        $this->tick();

        self::assertSame(Node::TERMINATED, $this->node('web-tokyo-late')->state);
        self::assertContains('terminate i-late', $this->aws->calls);
    }

    public function testInstanceLessNodeIsFinishedAfterTheGracePeriod(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 0]]]);
        $node = $this->t->addNode('web-tokyo-none', 'tokyo', Node::PROVISIONING, null, null, false);
        $this->t->app->reconciler()->cancel($node, 'test');

        $this->t->clock->advance(599);
        $this->tick(false);
        self::assertSame(Node::TERMINATING, $this->node('web-tokyo-none')->state);

        $this->t->clock->advance(1);
        $this->tick(false);
        self::assertSame(Node::TERMINATED, $this->node('web-tokyo-none')->state);
        self::assertSame([], $this->aws->callsMatching('terminate'));
    }

    public function testWarmupTimeoutFailsAndTerminates(): void
    {
        $this->tick();
        $id = $this->t->app->nodes()->liveInPool('tokyo')[0]->nodeId;
        $this->aws->boot($id, '10.0.0.1', '2001:db8::1');
        $this->tick();
        $node = $this->node($id);
        $node->v4->error = 'unexpected HTTP status 502';
        $node->probedAt = $this->t->clock->now();
        $this->t->app->nodes()->saveHealth($node);

        $this->t->clock->advance(901);
        $this->tick();

        $node = $this->node($id);
        self::assertSame(Node::TERMINATED, $node->state);
        self::assertStringContainsString('502', (string) $node->lastError);
        self::assertSame(Instance::GONE, $this->aws->instanceOf($id)->status);
        self::assertContains('warmup_timeout', $this->t->eventTypes());
        self::assertSame(2, $this->t->app->pools()->get('tokyo')->warmupFailures, 'both boot-stalled nodes timed out');
    }

    public function testLostServingNodeIsReplacedByMinFill(): void
    {
        [$a] = $this->serving(2);
        $this->aws->instances[$this->node($a)->instanceId]->status = Instance::GONE;

        $this->tick();

        self::assertSame(Node::TERMINATED, $this->node($a)->state);
        self::assertContains('node_lost', $this->t->eventTypes());
        self::assertCount(1, $this->aws->callsMatching('create'), 'replacement created to keep min');
    }

    public function testListFailureNeverMarksNodesLost(): void
    {
        [$a] = $this->serving(2);
        $this->aws->instances = [];
        $this->aws->listFails = true;

        $this->tick();

        self::assertSame(Node::IN_SERVICE, $this->node($a)->state);
        self::assertNotEmpty($this->t->logger->matching('Cannot list instances on aws'));
    }

    public function testDrainTimerStartsOnlyOnceNodeIsRemovedFromDns(): void
    {
        [$a] = $this->serving(2);
        $this->t->app->dnsSync()->sync();
        $this->t->app->reconciler()->drain($this->node($a), 'test');

        // DNS 同步一直失败，超过 drain.max 后才摘除
        $this->t->clock->advance(1801);
        $this->tick(false);
        self::assertSame(Node::DRAINING, $this->node($a)->state, 'records still in Route 53');
        self::assertNull($this->node($a)->dnsRemovedAt);
        self::assertNotEmpty($this->t->logger->matching('still in DNS'));

        $this->t->app->dnsSync()->sync();
        $this->tick(false);
        self::assertSame(Node::DRAINING, $this->node($a)->state, 'drain timer starts at removal');
        self::assertSame($this->t->clock->now(), $this->node($a)->dnsRemovedAt);

        $this->t->clock->advance(1799);
        $this->tick(false);
        self::assertSame(Node::DRAINING, $this->node($a)->state);

        $this->t->clock->advance(1);
        $this->tick(false);
        self::assertSame(Node::TERMINATED, $this->node($a)->state);
        self::assertContains('terminate ' . $this->node($a)->instanceId, $this->aws->calls);
    }

    public function testRecordAddedBackDuringDrainRestartsTheTimer(): void
    {
        [$a] = $this->serving(2);
        $this->t->app->dnsSync()->sync();
        $published = array_values(array_filter($this->t->network->route53->recordsNamed('tokyo.pool.app.example.com'), static function ($record) use ($a): bool {
            return $record->setIdentifier === $a;
        }));
        $this->t->app->reconciler()->drain($this->node($a), 'test');
        $this->t->app->dnsSync()->sync();
        $this->tick(false);
        self::assertNotNull($this->node($a)->dnsRemovedAt);

        // 排空快结束时，旧记录被外部加了回来（例如人工恢复）
        $this->t->clock->advance(1700);
        foreach ($published as $record) {
            $this->t->network->route53->add($record);
        }
        $this->t->clock->advance(100);
        $this->tick(false);

        self::assertSame(Node::DRAINING, $this->node($a)->state, 'still resolvable, so not terminated');
        self::assertNull($this->node($a)->dnsRemovedAt);
        self::assertContains('drain_restarted', $this->t->eventTypes());
        self::assertNotContains('terminate ' . $this->node($a)->instanceId, $this->aws->calls);

        // dnslb 再次摘除后从头计时
        $this->t->app->dnsSync()->sync();
        $this->tick(false);
        self::assertSame($this->t->clock->now(), $this->node($a)->dnsRemovedAt);
        $this->t->clock->advance(1800);
        $this->tick(false);
        self::assertSame(Node::TERMINATED, $this->node($a)->state);
    }

    public function testDnsReadFailurePostponesTerminationWithoutRestartingTheTimer(): void
    {
        [$a] = $this->serving(2);
        $this->t->app->dnsSync()->sync();
        $this->t->app->reconciler()->drain($this->node($a), 'test');
        $this->t->app->dnsSync()->sync();
        $this->tick(false);
        $removedAt = $this->node($a)->dnsRemovedAt;

        $this->t->network->route53->denied = true;
        $this->t->clock->advance(1800);
        $this->tick(false);
        self::assertSame(Node::DRAINING, $this->node($a)->state);
        self::assertSame($removedAt, $this->node($a)->dnsRemovedAt);
        self::assertNotEmpty($this->t->logger->matching('postponing drain checks'));

        $this->t->network->route53->denied = false;
        $this->tick(false);
        self::assertSame(Node::TERMINATED, $this->node($a)->state);
    }

    public function testDrainEndsEarlyWhenIdleMetricDropsAfterDrainMin(): void
    {
        $this->t = $this->app([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'drain' => ['min' => 300, 'max' => 1800, 'idle' => ['source' => 'prom', 'query' => 'rps', 'below' => 1]],
        ]);
        $prom = new \Wcore\Dnslb\Tests\Support\FakeMetricSource();
        $this->t->app->metrics()->set('prom', $prom);
        [$a, $b] = $this->serving(2);
        $this->t->app->reconciler()->drain($this->node($a), 'test');
        $this->t->app->reconciler()->drain($this->node($b), 'test');
        $this->t->app->dnsSync()->sync();
        $this->tick(false); // 确认已摘除，开始计时
        $prom->results['rps'] = [$a => 0.2, $b => 5.0];

        $this->t->clock->advance(299);
        $this->tick(false);
        self::assertSame(Node::DRAINING, $this->node($a)->state, 'drain.min not reached yet');

        $this->t->clock->advance(1);
        $this->t->app->metrics()->clearCache();
        $this->tick(false);
        self::assertSame(Node::TERMINATED, $this->node($a)->state, 'idle after drain.min');
        self::assertSame(Node::DRAINING, $this->node($b)->state, 'still serving traffic');
    }

    public function testDrainWaitsForDrainMax(): void
    {
        [$a] = $this->serving(2);
        $this->t->app->reconciler()->drain($this->node($a), 'test');

        $this->t->clock->advance(1799);
        $this->tick();

        self::assertSame(Node::DRAINING, $this->node($a)->state);
        self::assertSame([], $this->aws->callsMatching('terminate'));
    }

    public function testAboveMaxCancelsPendingFirstThenDrainsNewest(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 0, 'max' => 4]]]);
        $ids = $this->serving(3);
        $this->t->addNode('web-tokyo-pending', 'tokyo', Node::WARMING, '10.0.0.9', '2001:db8::9', false);
        $this->t = $this->withConfig(['pools' => ['tokyo' => ['min' => 0, 'max' => 2]]]);

        $this->tick();

        self::assertSame(Node::TERMINATING, $this->node('web-tokyo-pending')->state, 'cancelled; no instance found yet');
        self::assertSame(Node::IN_SERVICE, $this->node($ids[0])->state);
        self::assertSame(Node::IN_SERVICE, $this->node($ids[1])->state);
        self::assertSame(Node::DRAINING, $this->node($ids[2])->state, 'newest serving node drained');
    }

    public function testLoweringMaxRemovesReplacementTogetherWithTheNodeItReplaces(): void
    {
        $this->t = $this->app(['pools' => ['tokyo' => ['min' => 1, 'max' => 2]]]);
        [$a, $b] = $this->serving(2);
        $old = $this->node($a);
        $old->v4->healthy = false;
        $old->v4->since = $this->t->clock->now() - 600;
        $this->t->app->nodes()->saveHealth($old);
        $this->t->app->autoHealer()->run([]);
        $replacementId = (string) $this->node($a)->replacedBy;
        self::assertSame(Node::REPLACING, $this->node($a)->state);

        $this->t = $this->withConfig(['pools' => ['tokyo' => ['min' => 1, 'max' => 1]]]);
        $this->tick();
        $this->t->app->autoHealer()->run([]);

        self::assertSame(Node::TERMINATED, $this->node($replacementId)->state);
        self::assertSame(Node::DRAINING, $this->node($a)->state, 'not restored to service, so no new replacement');
        self::assertSame(Node::IN_SERVICE, $this->node($b)->state);
        self::assertCount(1, $this->aws->callsMatching('create'));
    }

    public function testRemovedPoolIsDrained(): void
    {
        [$a] = $this->serving(2);
        $this->t = $this->withConfig(['pools' => ['tokyo' => null]]);

        $this->tick();

        self::assertSame(Node::DRAINING, $this->node($a)->state);
    }

    public function testScaleInOrderPrefersUnhealthyThenOutdatedThenFallbackThenNewest(): void
    {
        $make = static function (string $id, int $index, int $created, bool $outdated = false, bool $healthy = true): Node {
            $node = new Node($id, 'tokyo', 'aws', 'x', $index, 10, $created);
            $node->outdated = $outdated;
            $node->ipv6 = '2001:db8::1';
            $node->v4->healthy = $healthy;
            $node->v6->healthy = true;
            return $node;
        };
        $v6Down = $make('v6-down', 0, 1);
        $v6Down->v6->healthy = false;
        $nodes = [
            $make('old-primary', 0, 100),
            $make('new-primary', 0, 300),
            $make('old-backup', 1, 50),
            $make('outdated', 0, 10, true),
            $make('unhealthy', 0, 5, false, false),
            $v6Down,
        ];
        $ids = static function (array $nodes): array {
            return array_map(static function (Node $n): string {
                return $n->nodeId;
            }, $nodes);
        };

        self::assertSame(
            ['unhealthy', 'v6-down', 'outdated', 'old-backup', 'new-primary', 'old-primary'],
            $ids(Reconciler::scaleInOrder($nodes, true))
        );
        self::assertSame(
            ['unhealthy', 'outdated', 'old-backup', 'new-primary', 'old-primary', 'v6-down'],
            $ids(Reconciler::scaleInOrder($nodes, false)),
            'IPv6 health is ignored when IPv6 is off'
        );
    }

    private function app(array $overrides = []): TestApp
    {
        $t = new TestApp(array_replace_recursive([
            'providers' => ['backup' => ['driver' => 'ec2']],
            'pools' => [
                'tokyo' => [
                    'min' => 2,
                    'launch' => [
                        ['provider' => 'aws', 'region' => 'ap-northeast-1', 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc'],
                        ['provider' => 'backup', 'region' => 'ap-northeast-1', 'instance_type' => 't3.small', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc', 'weight' => 20],
                    ],
                ],
                'frankfurt' => ['min' => 0],
            ],
            'autoheal' => ['max_warmup_failures' => 3],
        ], $overrides));
        $this->aws = new FakeProvider('aws');
        $this->backup = new FakeProvider('backup');
        $t->app->providers()->set('aws', $this->aws);
        $t->app->providers()->set('backup', $this->backup);
        return $t;
    }

    /**
     * 用新配置重建应用，沿用同一个数据库、云厂商和 Route 53。
     */
    private function withConfig(array $overrides): TestApp
    {
        $old = $this->t;
        $config = TestApp::config(array_replace_recursive([
            'providers' => ['backup' => ['driver' => 'ec2']],
            'pools' => ['tokyo' => ['min' => 2, 'launch' => [['provider' => 'aws', 'region' => 'ap-northeast-1', 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc']]], 'frankfurt' => ['min' => 0]],
        ], $overrides));
        $new = new TestApp();
        $new->network = $old->network;
        $new->clock = $old->clock;
        $new->logger = $old->logger;
        $new->app = new \Wcore\Dnslb\Application($config, 'test', false, [
            'transport' => $old->transport,
            'clock' => $old->clock,
            'logger' => $old->logger,
            'database' => $old->app->database(),
        ]);
        $new->app->providers()->set('aws', $this->aws);
        $new->app->providers()->set('backup', $this->backup);
        return $new;
    }

    /**
     * 直接造出 $count 个在线节点（实例在 FakeProvider 中，状态 running）。
     *
     * @return string[]
     */
    private function serving(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $id = "web-tokyo-s{$i}";
            $node = $this->t->addNode($id, 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}", "2001:db8::{$i}");
            $instance = new Instance('aws', "i-s{$i}", 'ap-northeast-1', Instance::RUNNING, $node->ipv4, $node->ipv6, $id);
            $this->aws->instances[$instance->id] = $instance;
            $node->instanceId = $instance->id;
            $node->createdAt -= 3600 - $i;
            $this->t->app->nodes()->save($node, $this->t->clock->now());
            $ids[] = $id;
        }
        return $ids;
    }

    private function markHealthy(string $nodeId): void
    {
        $node = $this->node($nodeId);
        $node->v4->healthy = true;
        $node->v6->healthy = true;
        $this->t->app->nodes()->saveHealth($node);
    }

    private function node(string $nodeId): Node
    {
        return $this->t->node($nodeId);
    }

    private function tick(bool $advance = true): void
    {
        $this->t->app->reconciler()->run();
        if ($advance) {
            $this->t->clock->advance(60);
        }
    }
}
