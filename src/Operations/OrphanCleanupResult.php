<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\OrphanInstance;

/**
 * Dnslb::cleanupOrphans() 的结果。
 *
 * 条目可能是实例，也可能是弹性 IP（OrphanInstance::isAddress() 为 true，此时 instanceId 是它的 allocation ID）；
 * $failed / $skipped 的键同样是实例 ID 或 allocation ID。
 */
final class OrphanCleanupResult
{
    public bool $dryRun;
    /** @var OrphanInstance[] 已确认、将要删除（预览）或尝试删除的孤儿实例 / 弹性 IP，不含 $skipped 中的 */
    public array $orphans = [];
    /** @var OrphanInstance[] 已删除的实例、已释放的弹性 IP（预览时为空） */
    public array $terminated = [];
    /** @var array<string,string> 删除 / 释放失败的 ID => 错误信息 */
    public array $failed = [];
    /** @var array<string,string> 不处理的 ID => 原因（节点仍在 DNS 中、读不到 DNS 记录，或弹性 IP 仍关联着别的实例） */
    public array $skipped = [];

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }
}
