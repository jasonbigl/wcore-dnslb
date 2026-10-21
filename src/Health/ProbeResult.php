<?php

namespace Wcore\Dnslb\Health;

final class ProbeResult
{
    public bool $ok;
    /** 失败原因，成功时为 null */
    public ?string $error;
    public ?int $status;
    /** 耗时（毫秒） */
    public int $latencyMs;

    public function __construct(bool $ok, ?string $error, ?int $status, int $latencyMs)
    {
        $this->ok = $ok;
        $this->error = $error;
        $this->status = $status;
        $this->latencyMs = $latencyMs;
    }
}
