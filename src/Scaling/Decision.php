<?php

namespace Wcore\Dnslb\Scaling;

final class Decision
{
    /** 正数扩容、负数缩容、0 不变 */
    public int $delta;
    public string $reason;
    /** 需要保存的"持续超过扩容阈值"开始时间 */
    public ?int $outBreachSince;
    /** 需要保存的"持续低于缩容阈值"开始时间 */
    public ?int $inBreachSince;

    public function __construct(int $delta, string $reason, ?int $outBreachSince = null, ?int $inBreachSince = null)
    {
        $this->delta = $delta;
        $this->reason = $reason;
        $this->outBreachSince = $outBreachSince;
        $this->inBreachSince = $inBreachSince;
    }
}
