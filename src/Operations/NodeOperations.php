<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Lifecycle\Reconciler;
use Wcore\Dnslb\State\Node;

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
     */
    public function cleanupOrphans(): OrphanCleanupResult
    {
        $tracker = $this->app->orphanTracker();
        $tracker->track(true);
        $result = new OrphanCleanupResult($this->app->isDryRun());
        foreach ($tracker->confirmed() as $orphan) {
            $result->orphans[] = $orphan;
            if ($result->dryRun) {
                continue;
            }
            try {
                $this->app->providers()->get($orphan->provider)->terminate($orphan->region, $orphan->instanceId);
            } catch (\Throwable $e) {
                $result->failed[$orphan->instanceId] = $e->getMessage();
                continue;
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
