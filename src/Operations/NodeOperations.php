<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Lifecycle\Reconciler;
use Wcore\Dnslb\Provider\Address;
use Wcore\Dnslb\Provider\ElasticIpProvider;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\OrphanInstance;

/**
 * 手动操作节点。调用方（Dnslb）负责持有 tick 锁，保证不与 tick 并发。
 */
final class NodeOperations
{
    private const ACTIVE_STATES = [Node::PROVISIONING, Node::BOOTING, Node::WARMING, Node::IN_SERVICE];

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * 扩容受 max 限制，缩容受 min 限制（剩下的健康节点也不少于 min）；缩容按"不健康的优先、备选建机方式优先、新节点优先"
     * 选择节点并立即同步 DNS。
     */
    public function scale(string $poolName, int $delta): ScaleResult
    {
        $pool = $this->pool($poolName);
        if ($delta === 0) {
            throw new \InvalidArgumentException('delta must not be 0');
        }
        $app = $this->app;
        $nodes = $app->nodes()->liveInPool($pool->name);
        $state = $app->pools()->get($pool->name);
        $now = $app->clock()->now();

        if ($delta > 0) {
            $active = count(array_filter($nodes, static function (Node $n): bool {
                return in_array($n->state, self::ACTIVE_STATES, true);
            }));
            $result = new ScaleResult($delta, max(0, min($delta, $pool->max - $active)));
            if ($result->allowed > 0) {
                $app->eventRecorder()->info('manual_scale', "手动扩容 {$result->allowed} 台", $pool->name);
                for ($i = 0; $i < $result->allowed; $i++) {
                    $node = $app->provisioner()->create($pool);
                    if ($node->state === Node::FAILED) {
                        $result->failed[] = $node;
                    } else {
                        $result->created[] = $node;
                    }
                }
                $state->lastScaleOutAt = $now;
            }
        } else {
            $inService = array_values(array_filter($nodes, static function (Node $n): bool {
                return $n->state === Node::IN_SERVICE;
            }));
            $targets = Reconciler::scaleInTargets($inService, -$delta, $pool->min, $app->config()->dns->ipv6);
            $result = new ScaleResult($delta, -count($targets));
            if ($targets !== []) {
                $app->eventRecorder()->info('manual_scale', '手动缩容 ' . count($targets) . ' 台', $pool->name);
                foreach ($targets as $node) {
                    $app->reconciler()->drain($node, '手动缩容');
                    $result->drained[] = $node;
                }
                $app->dnsSync()->sync($app->isDryRun());
                $state->lastScaleInAt = $now;
            }
        }
        $app->pools()->saveScaling($state);
        return $result;
    }

    /**
     * 排空在线节点：立即从 DNS 摘除，排空后删除机器。池的在线节点低于 min 时，下次 tick 自动补建。
     *
     * @return Node 排空中的节点
     */
    public function drain(string $nodeId): Node
    {
        $node = $this->node($nodeId);
        if (!in_array($node->state, [Node::IN_SERVICE, Node::REPLACING], true)) {
            throw new OperationException("Node {$nodeId} is {$node->state}; only in-service nodes can be drained");
        }
        $this->app->reconciler()->drain($node, '手动排空');
        $this->app->dnsSync()->sync($this->app->isDryRun());
        return $node;
    }

    /**
     * 替换在线节点：先建新机器，上线后再排空旧节点。
     *
     * @return Node 替补节点
     */
    public function replace(string $nodeId): Node
    {
        $node = $this->node($nodeId);
        if ($node->state !== Node::IN_SERVICE) {
            throw new OperationException("Node {$nodeId} is {$node->state}; only in-service nodes can be replaced");
        }
        $replacement = $this->app->autoHealer()->replaceManually($node);
        if ($replacement->state === Node::FAILED) {
            throw new OperationException("Could not create a replacement for {$nodeId}: {$replacement->lastError}");
        }
        return $replacement;
    }

    /**
     * 重新检查孤儿实例，删除已确认的（演练模式下只列出）。
     *
     * 状态库丢失或从备份恢复时，正在服务的机器也会被当成孤儿：节点 ID 仍在 DNS 中、而状态库里没有这个在线节点的
     * 实例一律跳过（删除会让用户访问失败，并释放仍被解析到的 IP）。状态库里有同 ID 的在线节点时，DNS 中的记录属于那台，
     * 孤儿是换建机方式时多建的一台，照常删除。读不到 DNS 记录时全部跳过。
     *
     * 孤儿弹性 IP 在实例之后处理，受同样的 DNS 保护。没有关联实例的直接释放；仍关联着实例的，只有它所属节点的
     * 孤儿实例也在本次删除时才一并释放，否则跳过（不动别的机器正在用的地址）。
     */
    public function cleanupOrphans(): OrphanCleanupResult
    {
        $tracker = $this->app->orphanTracker();
        $tracker->track(true);
        $result = new OrphanCleanupResult($this->app->isDryRun());
        $confirmed = $tracker->confirmed();
        if ($confirmed === []) {
            return $result;
        }
        // 先实例、后弹性 IP
        usort($confirmed, static function (OrphanInstance $a, OrphanInstance $b): int {
            return [$a->isAddress(), $a->firstSeenAt, $a->instanceId] <=> [$b->isAddress(), $b->firstSeenAt, $b->instanceId];
        });
        $addresses = $this->orphanAddresses($confirmed);
        /** @var array<string,true> 本次删除（预览时为将要删除）的孤儿实例的节点 ID */
        $removedNodes = [];
        $unreadable = null;
        try {
            $published = $this->app->dnsSync()->publishedNodeIds();
        } catch (\Throwable $e) {
            $published = [];
            $unreadable = $e->getMessage();
        }
        $live = [];
        foreach ($this->app->nodes()->live() as $node) {
            $live[$node->nodeId] = true;
        }

        foreach ($confirmed as $orphan) {
            if ($unreadable !== null) {
                $result->skipped[$orphan->instanceId] = "读不到 DNS 记录，无法确认是否仍在服务：{$unreadable}";
                continue;
            }
            if ($orphan->nodeId !== null && isset($published[$orphan->nodeId]) && !isset($live[$orphan->nodeId])) {
                $result->skipped[$orphan->instanceId] = "节点 {$orphan->nodeId} 仍在 DNS 中，可能正在服务（状态库丢失或从备份恢复？），先运行 rebuildState()";
                continue;
            }
            if ($orphan->isAddress()) {
                $this->cleanupOrphanAddress($orphan, $addresses, $removedNodes, $result);
                continue;
            }
            $result->orphans[] = $orphan;
            if ($result->dryRun) {
                if ($orphan->nodeId !== null) {
                    $removedNodes[$orphan->nodeId] = true;
                }
                continue;
            }
            try {
                $this->app->providers()->get($orphan->provider)->terminate($orphan->region, $orphan->instanceId);
            } catch (\Throwable $e) {
                $result->failed[$orphan->instanceId] = $e->getMessage();
                continue;
            }
            if ($orphan->nodeId !== null) {
                $removedNodes[$orphan->nodeId] = true;
            }
            $tracker->forget($orphan);
            $result->terminated[] = $orphan;
            $this->app->eventRecorder()->info(
                'orphan_terminated',
                "已删除孤儿实例 {$orphan->instanceId}（{$orphan->provider}/{$orphan->region}）",
                null,
                $orphan->nodeId
            );
        }
        return $result;
    }

    /**
     * 释放一个孤儿弹性 IP（已通过 DNS 检查）。
     *
     * @param array<string,array<string,Address>|null> $addresses 见 orphanAddresses()
     * @param array<string,true> $removedNodes 本次删除了孤儿实例的节点 ID
     */
    private function cleanupOrphanAddress(OrphanInstance $orphan, array $addresses, array $removedNodes, OrphanCleanupResult $result): void
    {
        $tracker = $this->app->orphanTracker();
        $current = $addresses[$orphan->provider] ?? null;
        if ($current === null) {
            $result->skipped[$orphan->instanceId] = '查不到弹性 IP 的当前状态，无法确认是否仍在使用';
            return;
        }
        $address = $current[$orphan->instanceId] ?? null;
        if ($address === null) {
            // 已经被释放
            if (!$result->dryRun) {
                $tracker->forget($orphan);
            }
            return;
        }
        // 仍关联着实例：只有这台实例是本次删除的孤儿时才解除关联并释放
        $detach = $orphan->nodeId !== null && isset($removedNodes[$orphan->nodeId]);
        if ($address->instanceId !== null && !$detach) {
            $result->skipped[$orphan->instanceId] = "弹性 IP {$address->ip} 仍关联着实例 {$address->instanceId}，不释放；该实例删除后再运行一次";
            return;
        }
        $result->orphans[] = $orphan;
        if ($result->dryRun) {
            return;
        }
        try {
            $provider = $this->app->providers()->get($orphan->provider);
            if (!$provider instanceof ElasticIpProvider) {
                throw new OperationException("Provider {$orphan->provider} does not manage elastic IPs");
            }
            $provider->releaseAddress($orphan->region, $orphan->instanceId, $detach);
        } catch (\Throwable $e) {
            $result->failed[$orphan->instanceId] = $e->getMessage();
            return;
        }
        $tracker->forget($orphan);
        $result->terminated[] = $orphan;
        $this->app->eventRecorder()->info(
            'orphan_address_released',
            "已释放孤儿弹性 IP {$address->ip}（{$orphan->instanceId}，{$orphan->provider}/{$orphan->region}）",
            null,
            $orphan->nodeId
        );
    }

    /**
     * 孤儿弹性 IP 的当前状态（是否仍关联着实例）。
     *
     * @param OrphanInstance[] $orphans
     * @return array<string,array<string,Address>|null> 云厂商 => 地址 ID => 地址；null 表示查询失败
     */
    private function orphanAddresses(array $orphans): array
    {
        $regions = [];
        foreach ($orphans as $orphan) {
            if ($orphan->isAddress()) {
                $regions[$orphan->provider][$orphan->region] = true;
            }
        }
        $result = [];
        foreach ($regions as $name => $providerRegions) {
            $result[$name] = null;
            try {
                $provider = $this->app->providers()->get($name);
                if (!$provider instanceof ElasticIpProvider) {
                    continue;
                }
                $result[$name] = [];
                foreach ($provider->listAddresses($this->app->config()->service, array_keys($providerRegions)) as $address) {
                    $result[$name][$address->id] = $address;
                }
            } catch (\Throwable $e) {
                $result[$name] = null;
                $this->app->logger()->warning("Cannot list elastic IPs on {$name}: {$e->getMessage()}");
            }
        }
        return $result;
    }

    /**
     * 开始发布：把池中现有节点标记为旧版本，之后由 tick 逐批替换。
     *
     * @param string|null $poolName null 表示所有池
     */
    public function rollout(?string $poolName, int $batch): RolloutResult
    {
        if ($batch < 1) {
            throw new \InvalidArgumentException('batch must be at least 1');
        }
        $pools = $this->poolNames($poolName);
        $now = $this->app->clock()->now();
        $paused = array_values(array_filter($pools, function (string $pool) use ($now): bool {
            $state = $this->app->pools()->get($pool);
            return !$state->manualPaused && $state->isPaused($now);
        }));
        if ($paused !== []) {
            throw new OperationException('Pool ' . implode(', ', $paused) . ' is paused after repeated startup failures; fix the cause and call resume() first');
        }

        $result = new RolloutResult();
        foreach ($pools as $pool) {
            $nodes = $this->app->rollout()->start($pool, $batch);
            if ($nodes === []) {
                continue;
            }
            $result->nodes[$pool] = $nodes;
            if ($this->app->pools()->get($pool)->manualPaused) {
                $result->warnings[] = "池 {$pool} 已手动暂停，resume() 之后才会开始替换";
            }
        }
        return $result;
    }

    /**
     * 停止发布。已经开始的替换会继续完成。
     *
     * @param string|null $poolName null 表示所有池
     */
    public function cancelRollout(?string $poolName): void
    {
        foreach ($this->poolNames($poolName) as $pool) {
            $this->app->rollout()->cancel($pool);
        }
    }

    /**
     * 暂停 / 恢复池的所有自动增删机器。恢复时同时解除连续启动失败造成的自动暂停。
     *
     * @param string|null $poolName null 表示所有池
     */
    public function setPaused(?string $poolName, bool $paused): void
    {
        foreach ($this->poolNames($poolName) as $pool) {
            $state = $this->app->pools()->get($pool);
            $state->manualPaused = $paused;
            if (!$paused) {
                $state->pausedUntil = null;
                $state->warmupFailures = 0;
            }
            $this->app->pools()->save($state);
            if ($paused) {
                $this->app->eventRecorder()->info('pool_manual_pause', '手动暂停自动增删机器', $pool);
            } else {
                $this->app->eventRecorder()->info('pool_manual_resume', '恢复自动增删机器', $pool);
            }
        }
    }

    /**
     * @return string[] null 表示所有池
     */
    private function poolNames(?string $poolName): array
    {
        return $poolName === null ? array_keys($this->app->config()->pools) : [$this->pool($poolName)->name];
    }

    private function pool(string $name): PoolConfig
    {
        $pool = $this->app->config()->pools[$name] ?? null;
        if ($pool === null) {
            throw new \InvalidArgumentException("Unknown pool: {$name}");
        }
        return $pool;
    }

    private function node(string $nodeId): Node
    {
        $node = $this->app->nodes()->find($nodeId);
        if ($node === null) {
            throw new \InvalidArgumentException("Unknown node: {$nodeId}");
        }
        return $node;
    }
}
