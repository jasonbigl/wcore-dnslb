<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Operations\CheckItem;

/**
 * 把 DNS 同步到节点状态对应的期望记录（Route53Sync / CloudflareSync）。
 *
 * health、tick 两个进程以及手动操作都通过这里写 DNS。实现需要在 DNS 锁内重新读取数据库计算期望记录，
 * 再与实际记录比对、只提交差异，这样任何一方用旧数据都不会覆盖另一方的变更。
 */
interface DnsSync
{
    public function sync(bool $dryRun = false): SyncResult;

    /**
     * 接管对外域名上不由 dnslb 管理的 A / AAAA / CNAME 记录，解析不中断。
     *
     * @throws \RuntimeException 还没有可发布的节点时拒绝接管
     */
    public function takeover(bool $dryRun = false): SyncResult;

    /**
     * 当前 DNS 中有记录的节点（排空的节点确认不在其中后才删除机器）。
     *
     * @return array<string,true> 节点 ID => true
     */
    public function publishedNodeIds(): array;

    /**
     * 期望记录的指纹（只读本地状态库）。health 进程每轮比较它，变化时才同步。
     */
    public function desiredFingerprint(int $now): string;

    /**
     * 只读检查凭证、托管区域和现有记录。
     *
     * @return CheckItem[]
     */
    public function check(): array;
}
