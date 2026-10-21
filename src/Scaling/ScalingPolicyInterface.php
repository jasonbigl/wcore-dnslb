<?php

namespace Wcore\Dnslb\Scaling;

/**
 * 扩缩容策略。目前只有固定步长（StepPolicy），目标跟踪模式见 README「后续迭代」。
 */
interface ScalingPolicyInterface
{
    public function decide(PoolSnapshot $snapshot): Decision;
}
