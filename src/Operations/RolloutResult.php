<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\Node;

/**
 * Dnslb::rollout() 的结果。
 */
final class RolloutResult
{
    /** @var array<string,Node[]> 池 => 标记为旧版本、将被逐台替换的节点（没有节点的池不在其中） */
    public array $nodes = [];
    /** @var string[] 需要注意的情况，例如池已手动暂停 */
    public array $warnings = [];

    /**
     * 待替换的节点总数。
     */
    public function count(): int
    {
        return array_sum(array_map('count', $this->nodes));
    }
}
