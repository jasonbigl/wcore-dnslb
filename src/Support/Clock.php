<?php

namespace Wcore\Dnslb\Support;

interface Clock
{
    /** 当前 Unix 时间戳（秒） */
    public function now(): int;

    /** 暂停指定的微秒数 */
    public function sleep(int $microseconds): void;
}
