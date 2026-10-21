<?php

namespace Wcore\Dnslb\Config;

final class MetricRule
{
    public const AGGREGATE_AVG = 'avg';
    public const AGGREGATE_MAX = 'max';

    public string $name;
    /** metric_sources 中的数据源名称 */
    public string $source;
    /** PromQL，需按节点返回、带 node_id 标签 */
    public string $query;
    public string $aggregate;
    public float $scaleOutAbove;
    public float $scaleInBelow;

    public static function fromSection(string $name, Section $s): self
    {
        $s->allowOnly(['source', 'query', 'aggregate', 'scale_out_above', 'scale_in_below']);
        $c = new self();
        $c->name = $name;
        $c->source = $s->string('source');
        $c->query = $s->string('query');
        $c->aggregate = $s->enum('aggregate', [self::AGGREGATE_AVG, self::AGGREGATE_MAX], self::AGGREGATE_AVG);
        $c->scaleOutAbove = $s->float('scale_out_above');
        $c->scaleInBelow = $s->float('scale_in_below');
        if ($s->has('scale_out_above') && $s->has('scale_in_below') && $c->scaleInBelow >= $c->scaleOutAbove) {
            $s->error('scale_in_below', 'must be lower than scale_out_above');
        }
        return $c;
    }
}
