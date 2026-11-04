<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\OrphanInstance;

/**
 * Dnslb::cleanupOrphans() 的结果。
 */
final class OrphanCleanupResult
{
    public bool $dryRun;
    /** @var OrphanInstance[] 已确认、将要删除（预览）或尝试删除的孤儿实例，不含 $skipped 中的 */
    public array $orphans = [];
    /** @var OrphanInstance[] 已删除的实例（预览时为空） */
    public array $terminated = [];
    /** @var array<string,string> 删除失败的实例 ID => 错误信息 */
    public array $failed = [];
    /** @var array<string,string> 不删除的实例 ID => 原因（节点仍在 DNS 中，或读不到 DNS 记录） */
    public array $skipped = [];

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }
}
