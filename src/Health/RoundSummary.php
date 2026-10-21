<?php

namespace Wcore\Dnslb\Health;

final class RoundSummary
{
    public int $targets;
    public int $failed = 0;
    public int $becameHealthy = 0;
    public int $becameUnhealthy = 0;
    /** @var string[] 因金丝雀不可达而丢弃结果的协议族 */
    public array $skippedFamilies = [];
    /** @var string[] 因全局冻结而丢弃结果的协议族 */
    public array $frozenFamilies = [];

    public function __construct(int $targets)
    {
        $this->targets = $targets;
    }
}
