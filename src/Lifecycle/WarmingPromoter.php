<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * health 进程每轮健康检查之后调用：有预热节点已通过检查时，试着拿 tick 锁（不等待）把它们转为在线，
 * 同一轮就同步 DNS，不用等下一个 tick（最多 1 分钟）。
 *
 * 节点状态只在 tick 锁内修改：tick、手动操作（scale / drain / replace 等）和这里都一样，所以不会互相覆盖。
 * tick 正在运行、拿不到锁时本轮跳过，下一轮再试；tick 自己也会照常转换。
 */
final class WarmingPromoter
{
    private Reconciler $reconciler;
    private FileLock $tickLock;
    private Logger $logger;

    public function __construct(Reconciler $reconciler, FileLock $tickLock, Logger $logger)
    {
        $this->reconciler = $reconciler;
        $this->tickLock = $tickLock;
        $this->logger = $logger;
    }

    /**
     * @return string[]|null 转为在线的节点 ID；tick 锁被占用时为 null（本轮跳过）
     */
    public function run(): ?array
    {
        // 先只读检查，没有候选节点时不去抢 tick 锁
        if (!$this->reconciler->hasPromotableWarming()) {
            return [];
        }
        if (!$this->tickLock->acquire(false)) {
            $this->logger->debug('Tick is running, warming nodes will be promoted next round');
            return null;
        }
        try {
            return $this->reconciler->promoteHealthyWarming();
        } finally {
            $this->tickLock->release();
        }
    }
}
