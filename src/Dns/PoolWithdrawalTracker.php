<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;

/**
 * Route 53 整池故障超过 pool_failover_after、该区域的 latency 记录真正被删除时记录事件（pool_withdrawn），
 * 让人知道这个区域的用户已经转到其他区域。pool_down 只说明"若未恢复将撤下"，撤下不一定发生：
 * 所有池都故障时全部保留发布，DNS 同步也可能失败。缩容到 0 台、从配置删除池不是故障，不记录。
 * 每个区域一个实例，各区域分别记录。
 */
final class PoolWithdrawalTracker
{
    private string $name;
    private Config $config;
    private PoolRepository $pools;
    private EventRecorder $events;
    private Clock $clock;

    public function __construct(string $name, Config $config, PoolRepository $pools, EventRecorder $events, Clock $clock)
    {
        $this->name = $name;
        $this->config = $config;
        $this->pools = $pools;
        $this->events = $events;
        $this->clock = $clock;
    }

    /**
     * 每次成功提交变更后调用。
     *
     * @param Change[] $changes
     */
    public function submitted(array $changes): void
    {
        $name = $this->name;
        $deleted = [];
        $kept = [];
        foreach ($changes as $change) {
            $record = $change->recordSet;
            if ($record->name !== $name || strpos((string) $record->setIdentifier, Route53Planner::LATENCY_ID_PREFIX) !== 0) {
                continue;
            }
            // 切换 pool_routing 时同一批次中先删后建，不算撤下
            $key = $record->setIdentifier . '|' . $record->type;
            if ($change->action === Change::DELETE) {
                $deleted[$key] = $record;
            } else {
                $kept[$key] = true;
            }
        }

        $now = $this->clock->now();
        foreach (array_diff_key($deleted, $kept) as $record) {
            $pool = substr((string) $record->setIdentifier, strlen(Route53Planner::LATENCY_ID_PREFIX));
            if (!isset($this->config->pools[$pool])) {
                continue;
            }
            $family = $record->type === 'AAAA' ? Node::FAMILY_V6 : Node::FAMILY_V4;
            $since = $this->pools->get($pool)->downSince($family);
            if ($since === null) {
                continue;
            }
            $this->events->info('pool_withdrawn', sprintf(
                '池 %s 整池故障 %d 秒，已把该区域（%s）从 %s 撤下，流量转到其他区域；恢复后自动重新发布',
                $family === Node::FAMILY_V6 ? 'IPv6' : 'IPv4',
                $now - $since,
                (string) $this->config->pools[$pool]->latencyRegion,
                $name
            ), $pool, null, ['dns_name' => $name]);
        }
    }
}
