<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\Node;

/**
 * Dnslb::rebuildState() 的结果。
 */
final class RebuildResult
{
    /** @var Node[] 补充到状态库的节点 */
    public array $restored = [];
    /** @var string[] 跳过或需要人工确认的情况 */
    public array $warnings = [];
    /** 所有云厂商都成功列出了实例 */
    public bool $complete = true;
}
