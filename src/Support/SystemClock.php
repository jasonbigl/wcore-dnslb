<?php

namespace Wcore\Dnslb\Support;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }

    public function sleep(int $microseconds): void
    {
        if ($microseconds > 0) {
            usleep($microseconds);
        }
    }
}
