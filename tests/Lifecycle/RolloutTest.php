<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Operations\OperationException;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Dnslb;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * 通过 Dnslb::rollout() 和完整的 tick 流程验证发布。
 */
final class RolloutTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;
    private Dnslb $lb;
    private int $ip = 10;

    protected function setUp(): void
    {
        $this->t = new TestApp([
            'dns' => ['ipv6' => false],
            'autoheal' => ['max_warmup_failures' => 2],
            'pools' => ['tokyo' => ['min' => 2, 'max' => 3], 'frankfurt' => ['min' => 0]],
        ]);
        $this->aws = new FakeProvider('aws');
        $this->t->app->providers()->set('aws', $this->aws);
        $this->lb = $this->t->lb(['aws' => $this->aws]);
        foreach (['a', 'b'] as $i => $suffix) {
            $node = $this->t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}");
            $this->aws->instances["i-{$suffix}"] = new Instance('aws', "i-{$suffix}", 'ap-northeast-1', Instance::RUNNING, $node->ipv4, null, $node->nodeId);
            $node->instanceId = "i-{$suffix}";
            $node->createdAt -= 3600 - $i;
            $this->t->app->nodes()->save($node, $this->t->clock->now());
        }
    }

    protected function tearDown(): void
    {
        $this->t->app->lock('tick')->release();
    }

    public function testReplacesOneNodeAtATimeUntilAllOldNodesAreGone(): void
    {
        $result = $this->lb->rollout();

        self::assertSame(2, $result->count());
        self::assertSame(['tokyo'], array_keys($result->nodes), 'frankfurt has no nodes');
        self::assertSame([], $result->warnings);
        self::assertSame(['started_at' => $this->t->clock->now(), 'batch' => 1, 'total' => 2, 'remaining' => 2, 'replacing' => 0], $this->tokyoStatus()['rollout']);

        $this->tick();
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-a')->state, 'oldest first');
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state);
        self::assertCount(1, $this->aws->callsMatching('create'));
        $first = (string) $this->t->node('web-tokyo-a')->replacedBy;

        $this->tick();
        self::assertCount(1, $this->aws->callsMatching('create'), 'batch of 1: waits for the replacement');
        self::assertSame(['A web-tokyo-a 10.0.0.0', 'A web-tokyo-b 10.0.0.1'], $this->t->records('tokyo.pool.app.example.com'), 'old node serves until replaced');

        $this->bringUp($first);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-b')->state, 'next node starts in the same tick');
        self::assertFalse($this->t->node($first)->outdated);
        self::assertSame(1, $this->tokyoStatus()['rollout']['remaining']);

        $this->bringUp((string) $this->t->node('web-tokyo-b')->replacedBy);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-b')->state);
        self::assertNull($this->tokyoStatus()['rollout']);
        self::assertNull($this->t->app->pools()->get('tokyo')->rolloutStartedAt);
        self::assertContains('rollout_completed', $this->t->eventTypes());
        self::assertFalse($this->t->node('web-tokyo-a')->outdated, 'flags are cleared');
        self::assertCount(2, $this->aws->callsMatching('create'));
        self::assertCount(2, $this->t->records('tokyo.pool.app.example.com'));
        self::assertNotContains('A web-tokyo-a 10.0.0.0', $this->t->records('tokyo.pool.app.example.com'));
    }

    public function testBatchReplacesSeveralNodesAtOnce(): void
    {
        $this->lb->rollout('tokyo', 2);

        $this->tick();

        self::assertCount(2, $this->aws->callsMatching('create'));
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-a')->state);
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-b')->state);
        self::assertSame(2, $this->tokyoStatus()['rollout']['replacing']);
    }

    public function testStopsWhenNewNodesKeepFailingAndLeavesOldNodesServing(): void
    {
        $this->lb->rollout();
        $this->aws->failCreate = 'image not found';

        $this->tick();
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-a')->state);
        self::assertContains('rollout_replace_failed', $this->t->eventTypes());
        $this->tick();
        self::assertTrue($this->t->app->pools()->get('tokyo')->isPaused($this->t->clock->now()), 'max_warmup_failures is 2');
        $this->tick();

        self::assertContains('rollout_aborted', $this->t->eventTypes());
        self::assertNull($this->tokyoStatus()['rollout']);
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-a')->state);
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state);
        self::assertFalse($this->t->node('web-tokyo-a')->outdated);
        self::assertCount(2, $this->aws->callsMatching('create'), 'no attempts after stopping');

        try {
            $this->lb->rollout();
            self::fail('Expected OperationException');
        } catch (OperationException $e) {
            self::assertStringContainsString('Pool tokyo is paused', $e->getMessage());
        }
        $this->lb->resume('tokyo');
        $this->aws->failCreate = null;
        self::assertSame(2, $this->lb->rollout()->count());
    }

    public function testWaitsWhilePausedOrHealthChecksAreFrozen(): void
    {
        $this->lb->pause('tokyo');
        $result = $this->lb->rollout();
        self::assertSame(['池 tokyo 已手动暂停，resume() 之后才会开始替换'], $result->warnings);

        $this->tick();
        self::assertSame([], $this->aws->callsMatching('create'));

        $this->lb->resume('tokyo');
        $this->t->app->meta()->set('frozen_v4', (string) $this->t->clock->now());
        $this->tick();
        self::assertSame([], $this->aws->callsMatching('create'));

        $this->t->app->meta()->delete('frozen_v4');
        $this->tick();
        self::assertCount(1, $this->aws->callsMatching('create'));
    }

    public function testCancelLetsStartedReplacementsFinish(): void
    {
        $this->lb->rollout();
        $this->tick();

        $this->lb->cancelRollout();

        self::assertNull($this->tokyoStatus()['rollout']);
        self::assertSame('手动停止发布：1 台正在替换的会继续完成，其余 1 台旧节点不再替换', $this->t->app->events()->recent(1)[0]->message);
        $this->bringUp((string) $this->t->node('web-tokyo-a')->replacedBy);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state);
        self::assertCount(1, $this->aws->callsMatching('create'));
    }

    public function testNodesCreatedAfterTheStartAreNotReplacedAndRestartMarksEverything(): void
    {
        $this->lb->rollout();
        $created = $this->lb->scale('tokyo', 1)->created[0];

        self::assertFalse($this->t->node($created->nodeId)->outdated);
        self::assertSame(2, $this->tokyoStatus()['rollout']['remaining']);

        self::assertSame(3, $this->lb->rollout()->count(), 'a new rollout supersedes the previous one');
        self::assertSame(3, $this->tokyoStatus()['rollout']['total']);
        self::assertContains('rollout_started', $this->t->eventTypes());
    }

    public function testDryRunAndValidation(): void
    {
        self::assertSame(2, $this->lb->rollout(null, 1, true)->count());
        self::assertNull($this->tokyoStatus()['rollout']);
        self::assertFalse($this->t->node('web-tokyo-a')->outdated);

        try {
            $this->lb->rollout('tokyo', 0);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('batch must be at least 1', $e->getMessage());
        }
        $this->expectExceptionMessage('Unknown pool: nope');
        $this->lb->rollout('nope');
    }

    /**
     * 让节点的实例启动并通过健康检查，然后运行两次 tick（BOOTING → WARMING → IN_SERVICE）。
     */
    private function bringUp(string $nodeId): void
    {
        $this->aws->boot($nodeId, '10.0.1.' . $this->ip++);
        $this->tick();
        $node = $this->t->node($nodeId);
        $node->v4->healthy = true;
        $this->t->app->nodes()->saveHealth($node);
        $this->tick();
        self::assertSame(Node::IN_SERVICE, $this->t->node($nodeId)->state);
    }

    private function tokyoStatus(): array
    {
        return $this->lb->status()['pools'][0];
    }

    private function tick(): void
    {
        self::assertTrue($this->lb->tick());
        $this->t->clock->advance(60);
    }
}
