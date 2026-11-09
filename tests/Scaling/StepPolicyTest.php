<?php

namespace Wcore\Dnslb\Tests\Scaling;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Scaling\Decision;
use Wcore\Dnslb\Scaling\PoolSnapshot;
use Wcore\Dnslb\Scaling\StepPolicy;
use Wcore\Dnslb\State\PoolState;
use Wcore\Dnslb\Tests\Support\TestApp;

final class StepPolicyTest extends TestCase
{
    private const NOW = 1700000000;

    private PoolConfig $pool;
    private StepPolicy $policy;

    protected function setUp(): void
    {
        $config = TestApp::config([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'scaling' => [
                'step' => 1,
                'sustain' => 300,
                'cooldown_out' => 600,
                'cooldown_in' => 900,
                'metrics' => [
                    'cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => 70, 'scale_in_below' => 30],
                    'bw' => ['source' => 'prom', 'query' => 'bw', 'scale_out_above' => 800, 'scale_in_below' => 200],
                ],
            ],
            'pools' => ['tokyo' => ['min' => 2, 'max' => 5]],
        ]);
        $this->pool = $config->pools['tokyo'];
        $this->policy = new StepPolicy();
    }

    public function testScaleOutNeedsSustainedBreach(): void
    {
        $first = $this->decide(3, 0, ['cpu' => 85, 'bw' => 100]);
        self::assertSame(0, $first->delta);
        self::assertSame(self::NOW, $first->outBreachSince);
        self::assertNull($first->inBreachSince);

        $state = new PoolState('tokyo');
        $state->outBreachSince = self::NOW - 299;
        self::assertSame(0, $this->decide(3, 0, ['cpu' => 85, 'bw' => 100], $state)->delta);

        $state->outBreachSince = self::NOW - 300;
        $decision = $this->decide(3, 0, ['cpu' => 85, 'bw' => 100], $state);
        self::assertSame(1, $decision->delta);
        self::assertSame('cpu=85 > 70', $decision->reason);
        self::assertNull($decision->outBreachSince, 'timer resets after acting');
    }

    public function testAnyMetricTriggersScaleOut(): void
    {
        $state = new PoolState('tokyo');
        $state->outBreachSince = self::NOW - 600;

        $decision = $this->decide(3, 0, ['cpu' => 20, 'bw' => 900], $state);

        self::assertSame(1, $decision->delta);
        self::assertSame('bw=900 > 800', $decision->reason);
    }

    public function testScaleOutBlockers(): void
    {
        $state = new PoolState('tokyo');
        $state->outBreachSince = self::NOW - 600;
        $hot = ['cpu' => 90, 'bw' => 100];

        $state->lastScaleOutAt = self::NOW - 599;
        self::assertStringContainsString('cooldown', $this->decide(3, 0, $hot, $state)->reason);
        $state->lastScaleOutAt = null;

        $pending = $this->decide(3, 1, $hot, $state);
        self::assertSame(0, $pending->delta);
        self::assertStringContainsString('waiting for 1 starting node', $pending->reason);
        self::assertSame(self::NOW - 600, $pending->outBreachSince, 'timer is kept while blocked');
        self::assertFalse($pending->atMax);

        $atMax = $this->decide(5, 0, $hot, $state);
        self::assertSame('cpu=90 > 70, already at max (5)', $atMax->reason);
        self::assertTrue($atMax->atMax);
        self::assertSame(self::NOW - 600, $atMax->outBreachSince);
    }

    public function testScaleOutIsCappedByMax(): void
    {
        $config = TestApp::config([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'scaling' => ['step' => 3, 'metrics' => ['cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => 70, 'scale_in_below' => 30]]],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 5]],
        ]);
        $state = new PoolState('tokyo');
        $state->outBreachSince = self::NOW - 600;

        $decision = $this->policy->decide(new PoolSnapshot($config->pools['tokyo'], 4, 0, ['cpu' => 90.0], $state, self::NOW));

        self::assertSame(1, $decision->delta);
    }

    public function testScaleInNeedsAllMetricsLowAndRespectsCooldownAndMin(): void
    {
        $state = new PoolState('tokyo');
        $state->inBreachSince = self::NOW - 300;

        self::assertSame(-1, $this->decide(4, 0, ['cpu' => 10, 'bw' => 50], $state)->delta);
        self::assertSame(0, $this->decide(4, 0, ['cpu' => 10, 'bw' => 300], $state)->delta, 'bw is not low');
        self::assertNull($this->decide(4, 0, ['cpu' => 10, 'bw' => 300], $state)->inBreachSince);
        self::assertStringContainsString('already at min', $this->decide(2, 0, ['cpu' => 10, 'bw' => 50], $state)->reason);

        $state->lastScaleOutAt = self::NOW - 800;
        self::assertStringContainsString('scale-in cooldown', $this->decide(4, 0, ['cpu' => 10, 'bw' => 50], $state)->reason);
    }

    public function testScaleInWhenRemainingNodesStayBelowScaleOutThresholds(): void
    {
        $state = new PoolState('tokyo');
        $state->inBreachSince = self::NOW - 300;

        // 3 台缩到 2 台：cpu 29 × 3 / 2 = 43.5 < 70，带宽 190 × 3 / 2 = 285 < 800，可以缩
        self::assertSame(-1, $this->decide(3, 0, ['cpu' => 29, 'bw' => 190], $state)->delta);
    }

    public function testPredictionBlocksScaleIn(): void
    {
        $config = TestApp::config([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'scaling' => ['metrics' => ['cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => 40, 'scale_in_below' => 30]]],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 5]],
        ]);
        $state = new PoolState('tokyo');
        $state->inBreachSince = self::NOW - 600;

        // 2 台平均 25%，缩到 1 台就是 50%，超过扩容阈值 40%：不缩
        $decision = $this->policy->decide(new PoolSnapshot($config->pools['tokyo'], 2, 0, ['cpu' => 25.0], $state, self::NOW));

        self::assertSame(0, $decision->delta);
        self::assertStringContainsString('push load above scale-out thresholds', $decision->reason);
    }

    public function testScaleInRemovesOneNodeRegardlessOfStep(): void
    {
        $pool = $this->poolWithStep(3, 70, 20);
        $low = new PoolState('tokyo');
        $low->inBreachSince = self::NOW - 300;
        $high = new PoolState('tokyo');
        $high->outBreachSince = self::NOW - 600;

        // 6 台 CPU 19%：一次缩 3 台的预测值 38% 也低于扩容阈值，但缩容每次只缩 1 台
        self::assertSame(-1, $this->policy->decide(new PoolSnapshot($pool, 6, 0, ['cpu' => 19.0], $low, self::NOW))->delta);
        self::assertSame(3, $this->policy->decide(new PoolSnapshot($pool, 6, 0, ['cpu' => 90.0], $high, self::NOW))->delta, 'scale-out still uses step');
    }

    public function testScaleInPredictionAssumesOneNodeRemovedRegardlessOfStep(): void
    {
        $pool = $this->poolWithStep(3, 40, 30);
        $state = new PoolState('tokyo');
        $state->inBreachSince = self::NOW - 300;

        // 4 台平均 25%，缩 1 台后约 33.3%，低于扩容阈值 40%：可以缩
        self::assertSame(-1, $this->policy->decide(new PoolSnapshot($pool, 4, 0, ['cpu' => 25.0], $state, self::NOW))->delta);

        // 2 台平均 25%，缩 1 台后 50%，超过扩容阈值 40%：不缩
        $blocked = $this->policy->decide(new PoolSnapshot($pool, 2, 0, ['cpu' => 25.0], $state, self::NOW));
        self::assertSame(0, $blocked->delta);
        self::assertStringContainsString('push load above scale-out thresholds', $blocked->reason);
    }

    public function testNewNodesStillSettlingHoldScalingAndKeepTheTimers(): void
    {
        $high = new PoolState('tokyo');
        $high->outBreachSince = self::NOW - 300;
        $held = $this->policy->decide(new PoolSnapshot($this->pool, 3, 0, ['cpu' => 85.0, 'bw' => 100.0], $high, self::NOW, 2));
        self::assertSame(0, $held->delta);
        self::assertSame(self::NOW - 300, $held->outBreachSince);
        self::assertStringContainsString('waiting for 2 new node(s) to settle', $held->reason);

        $atMax = $this->policy->decide(new PoolSnapshot($this->pool, 5, 0, ['cpu' => 85.0, 'bw' => 100.0], $high, self::NOW, 1));
        self::assertFalse($atMax->atMax, 'readings are not trusted yet, so no at-max notice');

        $low = new PoolState('tokyo');
        $low->inBreachSince = self::NOW - 300;
        $held = $this->policy->decide(new PoolSnapshot($this->pool, 3, 0, ['cpu' => 10.0, 'bw' => 50.0], $low, self::NOW, 1));
        self::assertSame(0, $held->delta);
        self::assertSame(self::NOW - 300, $held->inBreachSince);
        self::assertStringContainsString('waiting for 1 new node(s) to settle', $held->reason);
    }

    public function testUnavailableMetricMeansNoDecisionAndResetsTimers(): void
    {
        $state = new PoolState('tokyo');
        $state->outBreachSince = self::NOW - 3600;

        $decision = $this->decide(3, 0, ['cpu' => 90, 'bw' => null], $state);

        self::assertSame(0, $decision->delta);
        self::assertSame('metric bw unavailable', $decision->reason);
        self::assertNull($decision->outBreachSince);
    }

    private function poolWithStep(int $step, int $scaleOutAbove, int $scaleInBelow): PoolConfig
    {
        $config = TestApp::config([
            'metric_sources' => ['prom' => ['url' => 'https://prom.test']],
            'scaling' => ['step' => $step, 'metrics' => ['cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => $scaleOutAbove, 'scale_in_below' => $scaleInBelow]]],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 10]],
        ]);
        return $config->pools['tokyo'];
    }

    private function decide(int $serving, int $pending, array $metrics, ?PoolState $state = null): Decision
    {
        $metrics = array_map(static function ($v) {
            return $v === null ? null : (float) $v;
        }, $metrics);
        return $this->policy->decide(new PoolSnapshot($this->pool, $serving, $pending, $metrics, $state ?? new PoolState('tokyo'), self::NOW));
    }
}
