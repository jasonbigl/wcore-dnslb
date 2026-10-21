<?php

namespace Wcore\Dnslb\Config;

final class ScalingConfig
{
    public const POLICY_STEP = 'step';

    public string $policy;
    public int $step;
    /** 指标持续超过阈值多少秒才触发 */
    public int $sustain;
    public int $cooldownOut;
    public int $cooldownIn;
    /** 有指标数据的在线节点比例低于此值时不做决策 */
    public float $minCoverage;
    /** @var array<string,MetricRule> */
    public array $metrics = [];

    public static function fromSection(Section $s): self
    {
        $s->allowOnly(['policy', 'step', 'sustain', 'cooldown_out', 'cooldown_in', 'min_coverage', 'metrics']);
        $c = new self();
        $c->policy = $s->enum('policy', [self::POLICY_STEP], self::POLICY_STEP);
        $c->step = $s->int('step', 1, 1, 100);
        $c->sustain = $s->int('sustain', 300, 0);
        $c->cooldownOut = $s->int('cooldown_out', 600, 0);
        $c->cooldownIn = $s->int('cooldown_in', 900, 0);
        $c->minCoverage = $s->float('min_coverage', 0.5, 0.0, 1.0);
        foreach ($s->namedSections('metrics') as $name => $metric) {
            $c->metrics[$name] = MetricRule::fromSection($name, $metric);
        }
        return $c;
    }
}
