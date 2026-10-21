<?php

namespace Wcore\Dnslb\Scaling;

/**
 * 固定步长策略：
 * - 任一指标高于 scale_out_above 持续 sustain 秒 → 扩容 step 台；
 * - 所有指标低于 scale_in_below 持续 sustain 秒 → 缩容 step 台。
 *
 * 防抖：扩容冷却 cooldown_out；缩容冷却 cooldown_in（距上次任何扩缩容）；有节点启动中时不再动作；
 * 缩容前预测剩余节点的负载，若会超过扩容阈值则少缩或不缩。
 * 指标不可用时不做任何决策，并重新计算持续时间。
 */
final class StepPolicy implements ScalingPolicyInterface
{
    public function decide(PoolSnapshot $s): Decision
    {
        $scaling = $s->pool->scaling;
        if ($scaling->metrics === []) {
            return new Decision(0, 'no scaling metrics configured');
        }
        foreach ($scaling->metrics as $name => $rule) {
            if (($s->metrics[$name] ?? null) === null) {
                return new Decision(0, "metric {$name} unavailable");
            }
        }

        $above = [];
        $allBelow = true;
        foreach ($scaling->metrics as $name => $rule) {
            $value = $s->metrics[$name];
            if ($value > $rule->scaleOutAbove) {
                $above[] = sprintf('%s=%s > %s', $name, self::number($value), self::number($rule->scaleOutAbove));
            }
            if (!($value < $rule->scaleInBelow)) {
                $allBelow = false;
            }
        }

        $now = $s->now;
        $state = $s->state;
        $max = $s->pool->max;
        $active = $s->serving + $s->pending;

        if ($above !== []) {
            $since = $state->outBreachSince ?? $now;
            $reason = implode(', ', $above);
            if ($now - $since < $scaling->sustain) {
                return new Decision(0, "{$reason} for " . ($now - $since) . "s (sustain {$scaling->sustain}s)", $since);
            }
            if ($state->lastScaleOutAt !== null && $now - $state->lastScaleOutAt < $scaling->cooldownOut) {
                return new Decision(0, "{$reason}, in scale-out cooldown", $since);
            }
            if ($s->pending > 0) {
                return new Decision(0, "{$reason}, waiting for {$s->pending} starting node(s)", $since);
            }
            if ($active >= $max) {
                return new Decision(0, "{$reason}, already at max ({$max})", $since);
            }
            // 动作后重新计算持续时间
            return new Decision(min($scaling->step, $max - $active), $reason);
        }

        if ($allBelow) {
            $since = $state->inBreachSince ?? $now;
            $reason = 'all metrics below scale-in thresholds (' . $this->describe($s) . ')';
            if ($now - $since < $scaling->sustain) {
                return new Decision(0, "{$reason} for " . ($now - $since) . "s (sustain {$scaling->sustain}s)", null, $since);
            }
            $lastAction = max((int) $state->lastScaleInAt, (int) $state->lastScaleOutAt);
            if ($lastAction > 0 && $now - $lastAction < $scaling->cooldownIn) {
                return new Decision(0, "{$reason}, in scale-in cooldown", null, $since);
            }
            if ($s->pending > 0) {
                return new Decision(0, "{$reason}, waiting for {$s->pending} starting node(s)", null, $since);
            }
            $remove = min($scaling->step, $s->serving - $s->pool->min);
            if ($remove <= 0) {
                return new Decision(0, "{$reason}, already at min ({$s->pool->min})", null, $since);
            }
            while ($remove > 0 && $this->wouldTriggerScaleOut($s, $remove)) {
                $remove--;
            }
            if ($remove === 0) {
                return new Decision(0, "{$reason}, but removing a node would push load above scale-out thresholds", null, $since);
            }
            return new Decision(-$remove, $reason);
        }

        return new Decision(0, 'within thresholds (' . $this->describe($s) . ')');
    }

    /**
     * 预测缩掉 $remove 台后，剩余节点的负载是否会超过扩容阈值（按负载平均分摊估算）。
     */
    private function wouldTriggerScaleOut(PoolSnapshot $s, int $remove): bool
    {
        $remaining = $s->serving - $remove;
        if ($remaining <= 0) {
            return true;
        }
        foreach ($s->pool->scaling->metrics as $name => $rule) {
            if ($s->metrics[$name] * $s->serving / $remaining >= $rule->scaleOutAbove) {
                return true;
            }
        }
        return false;
    }

    private function describe(PoolSnapshot $s): string
    {
        $parts = [];
        foreach ($s->metrics as $name => $value) {
            $parts[] = "{$name}=" . self::number((float) $value);
        }
        return implode(', ', $parts);
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
