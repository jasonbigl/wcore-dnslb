<?php

namespace Wcore\Dnslb\Tests\Health;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\State\Event;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\TestApp;

final class HealthCheckerTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = new TestApp();
    }

    public function testWarmingNodeBecomesHealthyAfterRiseWithoutEvents(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::WARMING, '10.0.0.1', '2001:db8::1', false);

        $this->round();
        $node = $this->t->node('web-tokyo-a');
        self::assertFalse($node->v4->healthy);
        self::assertSame(1, $node->v4->passes);

        $secondRound = $this->t->clock->now();
        $this->round();
        $node = $this->t->node('web-tokyo-a');
        self::assertTrue($node->v4->healthy);
        self::assertTrue($node->v6->healthy);
        self::assertSame($secondRound, $node->v4->since);
        self::assertSame($secondRound, $node->probedAt);
        self::assertSame([], $this->t->eventTypes());
        self::assertSame(['10.0.0.1', '2001:db8::1', '10.0.0.1', '2001:db8::1'], $this->t->network->probed);
    }

    public function testServingNodeIsMarkedUnhealthyAfterFallAndRecovers(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2', '2001:db8::2');
        $this->t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1', '2001:db8:1::1');
        $this->t->network->status['10.0.0.1'] = 503;

        $summary = $this->round();
        self::assertSame(6, $summary->targets);
        self::assertSame(1, $summary->failed);
        self::assertTrue($this->t->node('web-tokyo-a')->v4->healthy, 'one failure is below fall=2');

        $this->round();
        $node = $this->t->node('web-tokyo-a');
        self::assertFalse($node->v4->healthy);
        self::assertTrue($node->v6->healthy, 'IPv6 is tracked separately');
        self::assertSame('unexpected HTTP status 503', $node->v4->error);
        self::assertSame(['node_unhealthy'], $this->t->eventTypes());

        unset($this->t->network->status['10.0.0.1']);
        $this->round();
        $this->round();
        self::assertTrue($this->t->node('web-tokyo-a')->v4->healthy);
        self::assertNull($this->t->node('web-tokyo-a')->v4->error);
        self::assertSame(['node_unhealthy', 'node_recovered'], $this->t->eventTypes());
    }

    public function testCanaryDownIgnoresResultsOfThatFamily(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2', '2001:db8::2');
        $this->t->network->canaryDown['v6'] = true;
        $this->t->network->unreachable['2001:db8::1'] = true;
        $this->t->network->unreachable['2001:db8::2'] = true;

        for ($i = 0; $i < 3; $i++) {
            $summary = $this->round();
        }

        self::assertSame(['v6'], $summary->skippedFamilies);
        self::assertTrue($this->t->node('web-tokyo-a')->v6->healthy, 'v6 results must be ignored');
        self::assertSame(0, $this->t->node('web-tokyo-a')->v6->fails);
        self::assertSame(['canary_down'], $this->t->eventTypes(), 'event recorded once, not every round');
        self::assertSame(Event::CRITICAL, $this->t->app->events()->recent(1)[0]->level);

        $this->t->network->canaryDown['v6'] = false;
        $this->t->network->unreachable = [];
        $this->round();
        self::assertSame(['canary_down', 'canary_recovered'], $this->t->eventTypes());
    }

    public function testGlobalFreezeKeepsNodesWhenMostServingNodesFail(): void
    {
        foreach (['a', 'b', 'c'] as $i => $suffix) {
            $this->t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}", null);
        }
        // 预热节点不计入冻结比例
        $this->t->addNode('web-tokyo-w', 'tokyo', Node::WARMING, '10.0.0.9', null, false);
        $this->t->network->unreachable = ['10.0.0.0' => true, '10.0.0.1' => true];

        for ($i = 0; $i < 3; $i++) {
            $summary = $this->round();
        }

        self::assertSame(['v4'], $summary->frozenFamilies);
        self::assertTrue($this->t->node('web-tokyo-a')->v4->healthy);
        self::assertTrue($this->t->node('web-tokyo-b')->v4->healthy);
        self::assertSame(0, $this->t->node('web-tokyo-w')->v4->passes, 'frozen rounds do not count for warming nodes either');
        self::assertSame(['global_freeze'], $this->t->eventTypes());

        $this->t->network->unreachable = ['10.0.0.0' => true];
        $this->round();
        self::assertSame(['global_freeze', 'global_freeze_lifted'], $this->t->eventTypes());
        self::assertSame(1, $this->t->node('web-tokyo-a')->v4->fails, '1 of 3 failing (33%) is below the threshold');
    }

    public function testFlagsOfFamiliesNoLongerProbedAreCleared(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        // IPv6 故障期间留下的标记，之后关闭了 IPv6
        $t->app->meta()->set('frozen_v6', (string) ($t->clock->now() - 600));
        $t->app->meta()->set('canary_down_v6', (string) ($t->clock->now() - 600));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $t->app->healthChecker()->runRound();

        self::assertNull($t->app->meta()->get('frozen_v6'));
        self::assertNull($t->app->meta()->get('canary_down_v6'));
        self::assertSame(['global_freeze_lifted', 'canary_recovered'], $t->eventTypes());
    }

    public function testPoolDownIsTrackedPerFamily(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $this->t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1', '2001:db8:1::1');
        $this->t->addNode('web-fra-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.2', '2001:db8:1::2');
        $this->t->network->unreachable['10.0.0.1'] = true;

        $this->round();
        $downAt = $this->t->clock->now();
        $this->round();

        $tokyo = $this->t->app->pools()->get('tokyo');
        self::assertSame($downAt, $tokyo->downSince('v4'));
        self::assertNull($tokyo->downSince('v6'));
        self::assertNull($this->t->app->pools()->get('frankfurt')->downSince('v4'));
        self::assertSame(['node_unhealthy', 'pool_down'], $this->t->eventTypes());

        $this->t->clock->advance(30);
        $this->round();
        self::assertSame($downAt, $this->t->app->pools()->get('tokyo')->downSince('v4'), 'start time is kept while still down');

        unset($this->t->network->unreachable['10.0.0.1']);
        $this->round();
        $this->round();
        self::assertNull($this->t->app->pools()->get('tokyo')->downSince('v4'));
        self::assertSame(['node_unhealthy', 'pool_down', 'node_recovered', 'pool_recovered'], $this->t->eventTypes());
    }

    public function testNodesWithoutIpv6AreProbedOnlyOverIpv4AndOtherStatesAreSkipped(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null);
        $this->t->addNode('web-tokyo-d', 'tokyo', Node::DRAINING, '10.0.0.2', '2001:db8::2');
        $this->t->addNode('web-tokyo-p', 'tokyo', Node::BOOTING, null, null);

        $summary = $this->round();

        self::assertSame(1, $summary->targets);
        self::assertSame(['10.0.0.1'], $this->t->network->probed);
    }

    private function round()
    {
        $summary = $this->t->app->healthChecker()->runRound();
        $this->t->clock->advance(10);
        return $summary;
    }
}
