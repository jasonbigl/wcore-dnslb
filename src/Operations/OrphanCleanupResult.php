<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\OrphanInstance;

/**
 * Dnslb::cleanupOrphans() 的结果。
 */
final class OrphanCleanupResult
{
    public bool $dryRun;
    /** @var OrphanInstance[] 已确认的孤儿实例 */
    public array $orphans = [];
    /** @var OrphanInstance[] 已删除的实例（预览时为空） */
    public array $terminated = [];
    /** @var array<string,string> 删除失败的实例 ID => 错误信息 */
    public array $failed = [];

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }
}
