<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Support\Clock;

/**
 * 可控时钟：sleep() 不真正等待，只推进时间并记录。
 */
final class FakeClock implements Clock
{
    public int $now;
    /** @var int[] 每次 sleep 的微秒数 */
    public array $sleeps = [];
    private int $pendingMicros = 0;

    public function __construct(int $now = 1700000000)
    {
        $this->now = $now;
    }

    public function now(): int
    {
        return $this->now;
    }

    public function sleep(int $microseconds): void
    {
        $this->sleeps[] = $microseconds;
        $this->pendingMicros += $microseconds;
        $this->now += intdiv($this->pendingMicros, 1000000);
        $this->pendingMicros %= 1000000;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
    }
}
