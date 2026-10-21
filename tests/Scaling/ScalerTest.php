<?php

namespace Wcore\Dnslb\Tests\Scaling;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Metrics\MetricException;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeMetricSource;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

final class ScalerTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;
    private FakeMetricSource $prom;

    protected function setUp(): void
    {
        $this->t = new TestApp([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'scaling' => [
                'sustain' => 120,
                'min_coverage' => 0.5,
                'metrics' => ['cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => 70, 'scale_in_below' => 30]],
            ],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 4], 'frankfurt' => ['min' => 0, 'max' => 2]],
        ]);
        $this->aws = new FakeProvider('aws');
        $this->t->app->providers()->set('aws', $this->aws);
        $this->prom = new FakeMetricSource();
        $this->t->app->metrics()->set('prom', $this->prom);
    }

    public function testScalesOutAfterSustainedLoad(): void
    {
        $this->serving(['a', 'b']);
        $this->prom->results['cpu'] = ['web-tokyo-a' => 90, 'web-tokyo-b' => 80, 'web-tokyo-old' => 99];
        // 排空中的节点不参与统计
        $this->t->addNode('web-tokyo-old', 'tokyo', Node::DRAINING, '10.0.0.9');

        $this->scale();
        self::assertSame([], $this->aws->callsMatching('create'));
        self::assertSame($this->t->clock->now(), $this->t->app->pools()->get('tokyo')->outBreachSince);

        $this->t->clock->advance(60);
        $this->scale();
        self::assertSame([], $this->aws->callsMatching('create'), 'sustain is 120s');

        $this->t->clock->advance(60);
        $this->scale();

        self::assertCount(1, $this->aws->callsMatching('create'));
        $state = $this->t->app->pools()->get('tokyo');
        self::assertSame($this->t->clock->now(), $state->lastScaleOutAt);
        self::assertNull($state->outBreachSince);
        self::assertSame(['scale_out', 'node_created'], $this->t->eventTypes());
        self::assertSame('扩容 1 台：cpu=85 > 70', $this->t->app->events()->recent(2)[1]->message);
        self::assertSame(['cpu'], array_values(array_unique($this->prom->queries)));
    }

    public function testScaleOutStartupFailuresPauseThePool(): void
    {
        $this->serving(['a', 'b']);
        $this->prom->results['cpu'] = ['web-tokyo-a' => 90, 'web-tokyo-b' => 80];
        $this->aws->failCreate = 'quota exceeded';

        for ($i = 1; $i <= 3; $i++) {
            $this->scale();
            $this->t->clock->advance(600);
            $this->scale();
            self::assertCount($i, $this->aws->callsMatching('create'));
            self::assertSame($i, $this->t->app->pools()->get('tokyo')->warmupFailures);
        }

        $state = $this->t->app->pools()->get('tokyo');
        self::assertTrue($state->isPaused($this->t->clock->now()), 'max_warmup_failures (3) reached');
        self::assertSame($this->t->clock->now(), $state->lastScaleOutAt);
        self::assertContains('pool_paused', $this->t->eventTypes());

        $this->scale();
        $this->t->clock->advance(600);
        $this->scale();
        self::assertCount(3, $this->aws->callsMatching('create'), 'paused pool does not scale out');
    }

    public function testScalesInByDrainingNewestNode(): void
    {
        $this->serving(['a', 'b', 'c']);
        $this->prom->results['cpu'] = ['web-tokyo-a' => 10, 'web-tokyo-b' => 12, 'web-tokyo-c' => 8];

        $this->scale();
        $this->t->clock->advance(120);
        $this->scale();

        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-c')->state);
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-a')->state);
        self::assertSame(['scale_in', 'node_draining'], $this->t->eventTypes());
    }

    public function testNodesRemovedFromDnsDoNotDragTheAverageDown(): void
    {
        $this->serving(['a', 'b', 'c']);
        $a = $this->t->node('web-tokyo-a');
        $a->v4->healthy = false;
        $a->v6->healthy = false;
        $this->t->app->nodes()->saveHealth($a);
        // a 已从 DNS 摘除，几乎没有负载；接流量的 b、c 已经过载
        $this->prom->results['cpu'] = ['web-tokyo-a' => 0, 'web-tokyo-b' => 80, 'web-tokyo-c' => 80];

        $this->scale();
        $this->t->clock->advance(120);
        $this->scale();

        self::assertCount(1, $this->aws->callsMatching('create'), 'average of b and c is 80 > 70');
    }

    public function testScaleInDrainsUnhealthyNodeInsteadOfTheOnlyHealthyOne(): void
    {
        $this->serving(['a', 'b']);
        $a = $this->t->node('web-tokyo-a');
        $a->v4->healthy = false;
        $this->t->app->nodes()->saveHealth($a);
        // 故障节点的指标很低，把平均值拉到缩容阈值以下
        $this->prom->results['cpu'] = ['web-tokyo-a' => 2, 'web-tokyo-b' => 25];

        $this->scale();
        $this->t->clock->advance(120);
        $this->scale();

        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state);
    }

    public function testDoesNotScaleInWhileTheOnlyOtherNodeIsBeingReplaced(): void
    {
        $this->serving(['a', 'b']);
        $a = $this->t->node('web-tokyo-a');
        $a->v4->healthy = false;
        $this->t->app->nodes()->saveHealth($a);
        $a->transition(Node::REPLACING, $this->t->clock->now());
        $a->replacedBy = 'web-tokyo-new';
        $this->t->app->nodes()->save($a, $this->t->clock->now());
        $new = $this->t->addNode('web-tokyo-new', 'tokyo', Node::BOOTING, null, null, false);
        $new->replacementFor = 'web-tokyo-a';
        $this->t->app->nodes()->save($new, $this->t->clock->now());
        $this->prom->results['cpu'] = ['web-tokyo-a' => 2, 'web-tokyo-b' => 25];

        $this->scale();
        $this->t->clock->advance(120);
        $this->scale();

        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state, 'b is the only healthy node');
        self::assertNotContains('scale_in', $this->t->eventTypes());
        self::assertNotEmpty($this->t->logger->matching('fewer than min healthy nodes'));
    }

    public function testReplacementsDoNotHoldBackScaling(): void
    {
        $this->serving(['a', 'b']);
        $a = $this->t->node('web-tokyo-a');
        $a->transition(Node::REPLACING, $this->t->clock->now());
        $this->t->app->nodes()->save($a, $this->t->clock->now());
        $replacement = $this->t->addNode('web-tokyo-new', 'tokyo', Node::BOOTING, null);
        $replacement->replacementFor = 'web-tokyo-a';
        $this->t->app->nodes()->save($replacement, $this->t->clock->now());
        $this->prom->results['cpu'] = ['web-tokyo-a' => 90, 'web-tokyo-b' => 80];

        $this->scale();
        $this->t->clock->advance(120);
        $this->scale();

        self::assertCount(1, $this->aws->callsMatching('create'), 'the replacement is not a starting node');
        self::assertEmpty($this->t->logger->matching('waiting for'));
    }

    public function testInsufficientCoverageOrQueryErrorsPreventDecisions(): void
    {
        $this->serving(['a', 'b', 'c']);
        $this->prom->results['cpu'] = ['web-tokyo-a' => 95];

        $this->scale();
        $this->t->clock->advance(600);
        $this->scale();
        self::assertSame([], $this->aws->calls);
        self::assertNotEmpty($this->t->logger->matching('covers 1 of 3 serving nodes'));

        $this->prom->results['cpu'] = new MetricException('connection refused');
        $this->t->clock->advance(600);
        $this->scale();
        self::assertSame([], $this->aws->calls);
        self::assertNotEmpty($this->t->logger->matching('connection refused'));
    }

    public function testPausedPoolDoesNotScaleOut(): void
    {
        $this->serving(['a']);
        $this->prom->results['cpu'] = ['web-tokyo-a' => 99];
        $state = $this->t->app->pools()->get('tokyo');
        $state->pausedUntil = $this->t->clock->now() + 3600;
        $state->outBreachSince = $this->t->clock->now() - 600;
        $this->t->app->pools()->save($state);

        $this->scale();

        self::assertSame([], $this->aws->calls);
        self::assertNotEmpty($this->t->logger->matching('is paused'));
    }

    /**
     * @param string[] $suffixes
     */
    private function serving(array $suffixes): void
    {
        foreach ($suffixes as $i => $suffix) {
            $node = $this->t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}", "2001:db8::{$i}");
            $node->createdAt -= 100 - $i;
            $this->t->app->nodes()->save($node, $this->t->clock->now());
        }
    }

    private function scale(): void
    {
        $this->t->app->metrics()->clearCache();
        $this->t->app->scaler()->run();
    }
}
