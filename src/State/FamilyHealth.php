<?php

namespace Wcore\Dnslb\State;

/**
 * 节点某一协议族（IPv4 / IPv6）的健康状态。
 */
final class FamilyHealth
{
    public bool $healthy = false;
    /** 连续失败次数 */
    public int $fails = 0;
    /** 连续成功次数 */
    public int $passes = 0;
    /** 进入当前健康状态的时间 */
    public ?int $since = null;
    /** 最近一次失败的原因 */
    public ?string $error = null;
}
