<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Metrics\MetricException;
use Wcore\Dnslb\Metrics\MetricSourceInterface;

/**
 * 按查询语句返回预设的节点数值；未预设的查询返回空结果。
 */
final class FakeMetricSource implements MetricSourceInterface
{
    /** @var array<string,array<string,float>|MetricException> */
    public array $results = [];
    /** @var string[] */
    public array $queries = [];

    public function queryByNode(string $query, string $nodeLabel = 'node_id'): array
    {
        $this->queries[] = $query;
        $result = $this->results[$query] ?? [];
        if ($result instanceof MetricException) {
            throw $result;
        }
        return $result;
    }
}
