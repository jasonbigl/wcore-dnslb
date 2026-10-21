<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

final class AutoHealerTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;

    protected function setUp(): void
    {
        $this->t = new TestApp([
            'autoheal' => ['unhealthy_for' => 300, 'max_unhealthy_ratio' => 0.5, 'max_replacements_per_hour' => 2],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 3], 'frankfurt' => ['min' => 0]],
        ]);
        $this->aws = new FakeProvider('aws');
        $this->t->app->providers()->set('aws', $this->aws);
    }

    public function testReplacesNodeThatStaysUnhealthyThenDrainsItOnceReplacementServes(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v6', 299);

        $this->heal();
        self::assertSame([], $this->aws->calls, 'not unhealthy long enough');

        $this->unhealthy('web-tokyo-0', 'v6', 300);
        $this->heal();

        $old = $this->t->node('web-tokyo-0');
        self::assertSame(Node::REPLACING, $old->state);
        $replacement = $this->t->node((string) $old->replacedBy);
        self::assertSame('web-tokyo-0', $replacement->replacementFor);
        self::assertSame(Node::BOOTING, $replacement->state);
        $event = $this->t->app->events()->recent(1)[0];
        self::assertSame('autoheal_replace', $event->type);
        self::assertStringContainsString('IPv6 持续不健康 300 秒', $event->message);

        // 替补上线前不重复创建
        $this->heal();
        self::assertCount(1, $this->aws->callsMatching('create'));

        $replacement->transition(Node::IN_SERVICE, $this->t->clock->now());
        $this->t->app->nodes()->save($replacement, $this->t->clock->now());
        $this->heal();
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-0')->state);
    }

    public function testLostNodeWithReplacementOnTheWayIsNotReplacedAgain(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v4', 600);
        $this->heal();
        self::assertCount(1, $this->aws->callsMatching('create'));

        // 替补启动期间旧节点的实例被停止，Reconciler 把它撤下并交给自动修复
        $lost = $this->t->node('web-tokyo-0');
        $lost->transition(Node::TERMINATED, $this->t->clock->now());
        $this->t->app->nodes()->save($lost, $this->t->clock->now());
        $this->t->app->autoHealer()->run([$lost]);

        self::assertCount(1, $this->aws->callsMatching('create'), 'the replacement already on its way is enough');
    }

    public function testReplacementCreatedBeforeACrashIsReused(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v4', 600);
        // 上次建出替补后、保存旧节点状态前进程退出
        $orphan = $this->t->addNode('web-tokyo-r', 'tokyo', Node::BOOTING, null, null, false);
        $orphan->replacementFor = 'web-tokyo-0';
        $this->t->app->nodes()->save($orphan, $this->t->clock->now());

        $this->heal();

        self::assertSame([], $this->aws->callsMatching('create'));
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-0')->state);
        self::assertSame('web-tokyo-r', $this->t->node('web-tokyo-0')->replacedBy);
    }

    public function testRecoveredNodeCancelsItsReplacement(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v4', 600);
        $this->heal();
        $replacementId = (string) $this->t->node('web-tokyo-0')->replacedBy;

        $node = $this->t->node('web-tokyo-0');
        $node->v4->healthy = true;
        $this->t->app->nodes()->saveHealth($node);
        $this->heal();

        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-0')->state);
        self::assertNull($this->t->node('web-tokyo-0')->replacedBy);
        self::assertSame(Node::TERMINATED, $this->t->node($replacementId)->state);
        self::assertCount(1, $this->aws->callsMatching('terminate'));
    }

    public function testFailedReplacementRevertsSoItCanBeRetried(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v4', 600);
        $this->heal();
        $replacement = $this->t->node((string) $this->t->node('web-tokyo-0')->replacedBy);
        $replacement->transition(Node::TERMINATED, $this->t->clock->now());
        $this->t->app->nodes()->save($replacement, $this->t->clock->now());

        $this->heal();

        self::assertCount(2, $this->aws->callsMatching('create'), 'reverted to IN_SERVICE and replaced again in the same run');
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-0')->state);
    }

    public function testLostNodeIsReplacedOneForOne(): void
    {
        $lost = $this->t->addNode('web-frankfurt-x', 'frankfurt', Node::TERMINATED, '10.0.1.1');

        $this->t->app->autoHealer()->run([$lost]);

        self::assertCount(1, $this->aws->callsMatching('create'));
        self::assertStringContainsString('实例丢失', $this->t->app->events()->recent(1)[0]->message);
    }

    public function testSystemicFailureOnlyAlertsOncePerHour(): void
    {
        $this->servingNodes(4);
        foreach ([0, 1, 2] as $i) {
            $this->unhealthy("web-tokyo-{$i}", 'v4', 900);
        }

        $this->heal();
        $this->heal();

        self::assertSame([], $this->aws->calls);
        self::assertSame(['autoheal_systemic'], $this->t->eventTypes());
    }

    public function testHourlyLimit(): void
    {
        $this->servingNodes(8);
        foreach ([0, 1, 2] as $i) {
            $this->unhealthy("web-tokyo-{$i}", 'v4', 900);
        }

        $this->heal();

        self::assertCount(2, $this->aws->callsMatching('create'));
        self::assertContains('autoheal_limited', $this->t->eventTypes());
    }

    public function testFrozenHealthChecksSkipUnhealthyReplacement(): void
    {
        $this->servingNodes(4);
        $this->unhealthy('web-tokyo-0', 'v4', 900);
        $this->t->app->meta()->set('frozen_v4', (string) $this->t->clock->now());

        $this->heal();

        self::assertSame([], $this->aws->calls);
    }

    private function servingNodes(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->t->addNode("web-tokyo-{$i}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}", "2001:db8::{$i}");
        }
    }

    private function unhealthy(string $nodeId, string $family, int $forSeconds): void
    {
        $node = $this->t->node($nodeId);
        $health = $node->health($family);
        $health->healthy = false;
        $health->since = $this->t->clock->now() - $forSeconds;
        $health->error = 'connect timeout';
        $this->t->app->nodes()->saveHealth($node);
    }

    private function heal(): void
    {
        $this->t->app->autoHealer()->run([]);
    }
}
