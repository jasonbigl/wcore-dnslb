<?php

namespace Wcore\Dnslb\Config;

final class AutohealConfig
{
    public bool $enabled;
    /** 在线节点持续不健康多少秒后替换 */
    public int $unhealthyFor;
    /** 池内不健康比例超过此值视为系统性故障，只告警不替换 */
    public float $maxUnhealthyRatio;
    public int $maxReplacementsPerHour;
    /** 同一池连续多少台新节点预热失败后暂停该池扩容 */
    public int $maxWarmupFailures;
    public int $pauseFor;

    public static function fromSection(Section $s): self
    {
        $s->allowOnly(['enabled', 'unhealthy_for', 'max_unhealthy_ratio', 'max_replacements_per_hour', 'max_warmup_failures', 'pause_for']);
        $c = new self();
        $c->enabled = $s->bool('enabled', true);
        $c->unhealthyFor = $s->int('unhealthy_for', 300, 0);
        $c->maxUnhealthyRatio = $s->float('max_unhealthy_ratio', 0.5, 0.01, 1.0);
        $c->maxReplacementsPerHour = $s->int('max_replacements_per_hour', 5, 0);
        $c->maxWarmupFailures = $s->int('max_warmup_failures', 3, 1);
        $c->pauseFor = $s->int('pause_for', 3600, 0);
        return $c;
    }
}
