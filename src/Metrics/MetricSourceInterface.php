<?php

namespace Wcore\Dnslb\Metrics;

interface MetricSourceInterface
{
    /**
     * 执行即时查询，返回每个节点的值。
     *
     * @return array<string,float> node_id => 值（NaN / Inf 被忽略）
     * @throws MetricException 查询失败或结果格式不对
     */
    public function queryByNode(string $query, string $nodeLabel = 'node_id'): array;
}
