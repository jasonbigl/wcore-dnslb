<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\State\Node;

/**
 * Dnslb::scale() 的结果。
 */
final class ScaleResult
{
    /** 请求的台数：正数扩容，负数缩容 */
    public int $requested;
    /** 受 max / min 限制后允许的台数（与 requested 同号，0 表示已达上限 / 下限） */
    public int $allowed;
    /** @var Node[] 已创建、等待上线的节点 */
    public array $created = [];
    /** @var Node[] 建机失败的节点（lastError 为原因） */
    public array $failed = [];
    /** @var Node[] 已从 DNS 摘除、开始排空的节点 */
    public array $drained = [];

    public function __construct(int $requested, int $allowed)
    {
        $this->requested = $requested;
        $this->allowed = $allowed;
    }
}
