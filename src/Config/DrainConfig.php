<?php

namespace Wcore\Dnslb\Config;

final class DrainConfig
{
    /** 从 DNS 摘除后至少等待的秒数 */
    public int $min;
    /** 最长等待秒数，到期无论流量如何都删除机器 */
    public int $max;
    /** 可选：判断节点已空闲的指标查询 */
    public ?string $idleSource = null;
    public ?string $idleQuery = null;
    public ?float $idleBelow = null;

    public static function fromSection(Section $s): self
    {
        $s->allowOnly(['min', 'max', 'idle']);
        $c = new self();
        $c->min = $s->int('min', 300, 0);
        $c->max = $s->int('max', 1800, 0);
        if ($c->max < $c->min) {
            $s->error('max', 'must be greater than or equal to min');
        }
        $idle = $s->section('idle');
        if ($idle !== null) {
            $idle->allowOnly(['source', 'query', 'below']);
            $c->idleSource = $idle->string('source');
            $c->idleQuery = $idle->string('query');
            $c->idleBelow = $idle->float('below');
        }
        return $c;
    }
}
