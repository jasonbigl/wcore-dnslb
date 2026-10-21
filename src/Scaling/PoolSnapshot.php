<?php

namespace Wcore\Dnslb\Scaling;

use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\State\PoolState;

/**
 * 做扩缩容决策所需的池快照。
 */
final class PoolSnapshot
{
    public PoolConfig $pool;
    /** 在线（IN_SERVICE / REPLACING）节点数 */
    public int $serving;
    /** 启动中（PROVISIONING / BOOTING / WARMING）节点数，不含替换中节点的替补 */
    public int $pending;
    /** @var array<string,float|null> 指标名 => 在线节点的聚合值；null 表示数据不可用 */
    public array $metrics;
    public PoolState $state;
    public int $now;

    /**
     * @param array<string,float|null> $metrics
     */
    public function __construct(PoolConfig $pool, int $serving, int $pending, array $metrics, PoolState $state, int $now)
    {
        $this->pool = $pool;
        $this->serving = $serving;
        $this->pending = $pending;
        $this->metrics = $metrics;
        $this->state = $state;
        $this->now = $now;
    }
}
