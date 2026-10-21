<?php

namespace Wcore\Dnslb\Scaling;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\MetricRule;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Lifecycle\Provisioner;
use Wcore\Dnslb\Lifecycle\Reconciler;
use Wcore\Dnslb\Metrics\MetricException;
use Wcore\Dnslb\Metrics\MetricRegistry;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 按指标为每个池做扩缩容。指标只统计在线（IN_SERVICE / REPLACING）且还在接流量的节点：
 * 启动中、排空中、已从 DNS 摘除的故障节点不参与，避免数据失真（例如死节点的 CPU 接近 0，会拉低平均值）。
 */
final class Scaler
{
    private const PENDING_STATES = [Node::PROVISIONING, Node::BOOTING, Node::WARMING];
    private const SERVING_STATES = [Node::IN_SERVICE, Node::REPLACING];

    private Config $config;
    private MetricRegistry $metrics;
    private NodeRepository $nodes;
    private PoolRepository $pools;
    private Provisioner $provisioner;
    private Reconciler $reconciler;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        MetricRegistry $metrics,
        NodeRepository $nodes,
        PoolRepository $pools,
        Provisioner $provisioner,
        Reconciler $reconciler,
        EventRecorder $events,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->metrics = $metrics;
        $this->nodes = $nodes;
        $this->pools = $pools;
        $this->provisioner = $provisioner;
        $this->reconciler = $reconciler;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function run(): void
    {
        foreach ($this->config->pools as $pool) {
            if ($pool->scaling->metrics === []) {
                continue;
            }
            try {
                $this->scale($pool);
            } catch (\Throwable $e) {
                $this->logger->error("Scaling pool {$pool->name} failed: {$e->getMessage()}", ['exception' => get_class($e)]);
            }
        }
    }

    private function scale(PoolConfig $pool): void
    {
        $now = $this->clock->now();
        $nodes = $this->nodes->liveInPool($pool->name);
        $serving = self::inStates($nodes, self::SERVING_STATES);
        $inService = self::inStates($nodes, [Node::IN_SERVICE]);
        // 替换中的节点和它的替补只算一台（算作在线），替换（自动修复、发布）不会让扩缩容等待"节点启动中"
        $replacing = [];
        foreach (self::inStates($nodes, [Node::REPLACING]) as $node) {
            $replacing[$node->nodeId] = true;
        }
        $pending = array_filter(self::inStates($nodes, self::PENDING_STATES), static function (Node $node) use ($replacing): bool {
            return $node->replacementFor === null || !isset($replacing[$node->replacementFor]);
        });

        $ipv6 = $this->config->dns->ipv6;
        $reporting = array_values(array_filter($serving, static function (Node $node) use ($ipv6): bool {
            return $node->receivesTraffic($ipv6);
        }));
        $values = [];
        foreach ($pool->scaling->metrics as $name => $rule) {
            $values[$name] = $this->aggregate($pool, $rule, $reporting);
        }

        $state = $this->pools->get($pool->name);
        if ($state->manualPaused) {
            $this->logger->debug("Pool {$pool->name} is manually paused, not scaling");
            return;
        }
        $decision = $this->policy($pool)->decide(new PoolSnapshot($pool, count($serving), count($pending), $values, $state, $now));
        $state->outBreachSince = $decision->outBreachSince;
        $state->inBreachSince = $decision->inBreachSince;
        $this->logger->debug("Pool {$pool->name}: {$decision->reason}", ['delta' => $decision->delta]);

        if ($decision->delta > 0) {
            if ($state->isPaused($now)) {
                $this->logger->warning("Pool {$pool->name} should scale out ({$decision->reason}) but is paused");
            } else {
                $this->events->info('scale_out', "扩容 {$decision->delta} 台：{$decision->reason}", $pool->name, null, ['metrics' => $values]);
                for ($i = 0; $i < $decision->delta; $i++) {
                    $node = $this->provisioner->create($pool);
                    if ($node->state === Node::FAILED) {
                        $this->reconciler->recordStartupFailure($pool->name);
                        break;
                    }
                }
                $state->lastScaleOutAt = $now;
            }
        } elseif ($decision->delta < 0) {
            // 替换中的节点也算在线，但只排空 IN_SERVICE 的节点
            $targets = Reconciler::scaleInTargets($inService, -$decision->delta, $pool->min, $this->config->dns->ipv6);
            if ($targets === []) {
                $this->logger->info("Pool {$pool->name} should scale in ({$decision->reason}) but would be left with fewer than min healthy nodes");
            } else {
                $this->events->info('scale_in', '缩容 ' . count($targets) . " 台：{$decision->reason}", $pool->name, null, ['metrics' => $values]);
                foreach ($targets as $node) {
                    $this->reconciler->drain($node, '缩容');
                }
                $state->lastScaleInAt = $now;
            }
        }
        $this->pools->saveScaling($state);
    }

    /**
     * 在线且还在接流量的节点的聚合值；数据覆盖率不足或查询失败时返回 null。
     *
     * @param Node[] $reporting
     */
    private function aggregate(PoolConfig $pool, MetricRule $rule, array $reporting): ?float
    {
        if ($reporting === []) {
            return null;
        }
        try {
            $byNode = $this->metrics->queryByNode($rule->source, $rule->query);
        } catch (MetricException $e) {
            $this->logger->warning("Metric {$rule->name} unavailable for pool {$pool->name}: {$e->getMessage()}");
            return null;
        }
        $values = [];
        foreach ($reporting as $node) {
            if (isset($byNode[$node->nodeId])) {
                $values[] = $byNode[$node->nodeId];
            }
        }
        $coverage = count($values) / count($reporting);
        if ($values === [] || $coverage < $pool->scaling->minCoverage) {
            $this->logger->warning(sprintf(
                'Metric %s covers %d of %d serving nodes in pool %s (min_coverage %s), not deciding',
                $rule->name,
                count($values),
                count($reporting),
                $pool->name,
                $pool->scaling->minCoverage
            ));
            return null;
        }
        return $rule->aggregate === MetricRule::AGGREGATE_MAX ? max($values) : array_sum($values) / count($values);
    }

    private function policy(PoolConfig $pool): ScalingPolicyInterface
    {
        // 目前只有 step 策略
        return new StepPolicy();
    }

    /**
     * @param Node[] $nodes
     * @param string[] $states
     * @return Node[]
     */
    private static function inStates(array $nodes, array $states): array
    {
        return array_values(array_filter($nodes, static function (Node $node) use ($states): bool {
            return in_array($node->state, $states, true);
        }));
    }
}
