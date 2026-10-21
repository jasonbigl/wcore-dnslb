<?php

namespace Wcore\Dnslb\Dns;

final class SyncResult
{
    /** @var DnsChange[] 按提交顺序排列 */
    public array $changes;
    /** @var string[] Route 53 变更 ID（变更过多时分多批提交）；Cloudflare 没有变更 ID，为空 */
    public array $changeIds;
    public bool $dryRun;
    /** 对外域名上还有不由 dnslb 管理的记录，等待 takeover（Route 53 只同步池记录，Cloudflare 不发布任何记录） */
    public bool $takeoverPending = false;

    /**
     * @param DnsChange[] $changes
     * @param string[] $changeIds
     */
    public function __construct(array $changes, array $changeIds, bool $dryRun)
    {
        $this->changes = $changes;
        $this->changeIds = $changeIds;
        $this->dryRun = $dryRun;
    }

    public function hasChanges(): bool
    {
        return $this->changes !== [];
    }
}
