<?php

namespace Wcore\Dnslb\Health;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Dns\NodeSelector;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 执行一轮健康检查并更新数据库中的健康状态。不直接修改 DNS，DNS 由 DnsSync 根据健康状态同步。
 *
 * 某个协议族出现以下情况时，本轮该协议族的结果全部丢弃，不改变任何节点状态：
 * - 金丝雀地址全部不可达：探测机自身网络有问题；
 * - 在线节点失败比例超过 global_freeze_ratio：很可能是探测机到节点的线路问题，或所有节点同时故障，
 *   此时不摘除任何节点（全局冻结），只告警。
 *
 * 节点摘除 / 恢复、整池故障事件按 NodeSelector 实际选出的发布结果描述：没有其他健康节点可以承接时，
 * 不健康的节点会保留在 DNS 中（不会让域名没有记录）。
 */
final class HealthChecker
{
    /** 需要探测的状态：预热中（判断能否上线）和在线 */
    private const PROBED_STATES = [Node::WARMING, Node::IN_SERVICE, Node::REPLACING];
    /** 在 DNS 中提供服务的状态 */
    private const SERVING_STATES = [Node::IN_SERVICE, Node::REPLACING];
    private const BECAME_UNHEALTHY = 'unhealthy';
    private const BECAME_HEALTHY = 'healthy';

    private Config $config;
    private Prober $prober;
    private NodeRepository $nodes;
    private PoolRepository $pools;
    private NodeSelector $selector;
    private Meta $meta;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        Prober $prober,
        NodeRepository $nodes,
        PoolRepository $pools,
        NodeSelector $selector,
        Meta $meta,
        EventRecorder $events,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->prober = $prober;
        $this->nodes = $nodes;
        $this->pools = $pools;
        $this->selector = $selector;
        $this->meta = $meta;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function runRound(): RoundSummary
    {
        $now = $this->clock->now();
        $nodes = $this->nodes->byStates(self::PROBED_STATES);
        $families = $this->config->dns->ipv6 ? [Node::FAMILY_V4, Node::FAMILY_V6] : [Node::FAMILY_V4];

        $targets = [];
        $probedFamilies = [];
        foreach ($nodes as $node) {
            foreach ($families as $family) {
                $ip = $node->ip($family);
                if ($ip !== null) {
                    $targets[] = new ProbeTarget($node->nodeId, $family, $ip);
                    $probedFamilies[$family] = true;
                }
            }
        }

        $summary = new RoundSummary(count($targets));
        /** @var array<array{0: Node, 1: string, 2: string}> 在线节点的健康变化：节点、协议族、变化 */
        $changes = [];
        $round = $this->prober->run($targets, array_keys($probedFamilies));
        $nodesById = [];
        foreach ($nodes as $node) {
            $nodesById[$node->nodeId] = $node;
        }

        foreach (array_keys($probedFamilies) as $family) {
            $familyTargets = array_values(array_filter($targets, static function (ProbeTarget $t) use ($family): bool {
                return $t->family === $family;
            }));
            foreach ($familyTargets as $target) {
                if (!$round->results[$target->key()]->ok) {
                    $summary->failed++;
                }
            }

            if (!$this->checkCanary($family, $round->canaryOk[$family] ?? false, $now)) {
                $summary->skippedFamilies[] = $family;
                continue;
            }
            if ($this->checkFreeze($family, $familyTargets, $nodesById, $round, $now)) {
                $summary->frozenFamilies[] = $family;
                continue;
            }
            foreach ($familyTargets as $target) {
                $node = $nodesById[$target->nodeId];
                $change = $this->apply($node, $family, $round->results[$target->key()], $now, $summary);
                if ($change !== null) {
                    $changes[] = [$node, $family, $change];
                }
            }
        }

        foreach ([Node::FAMILY_V4, Node::FAMILY_V6] as $family) {
            if (!isset($probedFamilies[$family])) {
                $this->clearStaleFlags($family, $now);
            }
        }

        // NodeSelector 读状态库：保存之前选出的是上一轮的发布结果，保存之后是本轮的
        $before = $changes === [] ? [] : $this->selected($now);
        foreach ($nodes as $node) {
            $node->probedAt = $now;
            $this->nodes->saveHealth($node);
        }
        if ($changes !== []) {
            $this->recordChanges($changes, $before, $this->selected($now));
        }
        $this->trackPools($nodes, $families, $now);

        $this->logger->debug('Health round finished', [
            'targets' => $summary->targets,
            'failed' => $summary->failed,
            'became_unhealthy' => $summary->becameUnhealthy,
            'became_healthy' => $summary->becameHealthy,
        ]);
        return $summary;
    }

    /**
     * @return bool 探测机在该协议族上网络正常
     */
    private function checkCanary(string $family, bool $ok, int $now): bool
    {
        $key = "canary_down_{$family}";
        $downSince = $this->meta->get($key);
        $label = self::familyLabel($family);
        if ($ok) {
            if ($downSince !== null) {
                $this->meta->delete($key);
                $this->events->info('canary_recovered', "探测机 {$label} 网络已恢复，恢复处理该协议族的健康检查结果");
            }
            return true;
        }
        if ($downSince === null) {
            $this->meta->set($key, (string) $now);
            $this->events->critical(
                'canary_down',
                "探测机无法通过 {$label} 访问任何金丝雀地址，暂停处理 {$label} 健康检查结果（不会因此摘除节点）",
                null,
                null,
                ['canary_urls' => $this->config->healthCheck->canaryUrls]
            );
        } else {
            $this->logger->warning("Canary still unreachable over {$label}, ignoring {$label} results", ['since' => (int) $downSince]);
        }
        return false;
    }

    /**
     * @param ProbeTarget[] $targets
     * @param array<string,Node> $nodesById
     * @return bool 是否冻结
     */
    private function checkFreeze(string $family, array $targets, array $nodesById, ProbeRound $round, int $now): bool
    {
        $serving = 0;
        $failing = 0;
        foreach ($targets as $target) {
            if (in_array($nodesById[$target->nodeId]->state, self::SERVING_STATES, true)) {
                $serving++;
                if (!$round->results[$target->key()]->ok) {
                    $failing++;
                }
            }
        }
        $ratio = $serving === 0 ? 0.0 : $failing / $serving;
        $threshold = $this->config->healthCheck->globalFreezeRatio;
        // 只有 1 台在线节点时比例没有意义（任何故障都是 100%），按正常流程处理：
        // 它不会被摘掉（NodeSelector 在所有节点都不健康时全部恢复发布），自动修复可以替换它
        $frozen = $serving > 1 && $ratio > $threshold;

        $key = "frozen_{$family}";
        $frozenSince = $this->meta->get($key);
        $label = self::familyLabel($family);
        if ($frozen && $frozenSince === null) {
            $this->meta->set($key, (string) $now);
            $this->events->critical(
                'global_freeze',
                sprintf('%d/%d 个在线节点 %s 健康检查同时失败，超过 %d%%，冻结 DNS 变更（不摘除任何节点）。请检查探测机网络或应用是否整体故障',
                    $failing, $serving, $label, (int) round($threshold * 100)),
                null,
                null,
                ['failing' => $failing, 'serving' => $serving]
            );
        } elseif ($frozen) {
            $this->logger->warning("{$label} health results still frozen", ['failing' => $failing, 'serving' => $serving]);
        } elseif ($frozenSince !== null) {
            $this->meta->delete($key);
            $this->events->info('global_freeze_lifted', "{$label} 健康检查冻结解除，恢复正常处理", null, null, [
                'frozen_seconds' => $now - (int) $frozenSince,
            ]);
        }
        return $frozen;
    }

    /**
     * 不再探测的协议族（例如关闭了 IPv6，或没有节点有该协议族的地址）：清除残留的冻结和金丝雀标记，
     * 否则冻结标记会一直挡住自动修复和发布。
     */
    private function clearStaleFlags(string $family, int $now): void
    {
        $label = self::familyLabel($family);
        $frozenSince = $this->meta->get("frozen_{$family}");
        if ($frozenSince !== null) {
            $this->meta->delete("frozen_{$family}");
            $this->events->info('global_freeze_lifted', "{$label} 已没有需要探测的节点，冻结解除", null, null, [
                'frozen_seconds' => $now - (int) $frozenSince,
            ]);
        }
        if ($this->meta->get("canary_down_{$family}") !== null) {
            $this->meta->delete("canary_down_{$family}");
            $this->events->info('canary_recovered', "{$label} 已没有需要探测的节点，清除探测机 {$label} 网络故障标记");
        }
    }

    /**
     * @return string|null 在线节点变为不健康 / 恢复健康时返回 BECAME_UNHEALTHY / BECAME_HEALTHY
     */
    private function apply(Node $node, string $family, ProbeResult $result, int $now, RoundSummary $summary): ?string
    {
        $health = $node->health($family);
        $check = $this->config->healthCheck;
        $serving = in_array($node->state, self::SERVING_STATES, true);

        if ($health->since === null) {
            $health->since = $now;
        }
        if ($result->ok) {
            $health->passes++;
            $health->fails = 0;
            $health->error = null;
            if (!$health->healthy && $health->passes >= $check->rise) {
                $health->healthy = true;
                $health->since = $now;
                $summary->becameHealthy++;
                return $serving ? self::BECAME_HEALTHY : null;
            }
            return null;
        }

        $health->fails++;
        $health->passes = 0;
        $health->error = $result->error;
        if ($health->healthy && $health->fails >= $check->fall) {
            $health->healthy = false;
            $health->since = $now;
            $summary->becameUnhealthy++;
            return $serving ? self::BECAME_UNHEALTHY : null;
        }
        return null;
    }

    /**
     * 记录节点摘除 / 恢复事件，按发布结果描述是否真的从 DNS 摘除。
     *
     * @param array<array{0: Node, 1: string, 2: string}> $changes
     * @param array<string,array<string,Node>> $before 本轮之前选出发布的节点：协议族 => 节点 ID => 节点
     * @param array<string,array<string,Node>> $after  本轮之后选出发布的节点
     */
    private function recordChanges(array $changes, array $before, array $after): void
    {
        foreach ($changes as [$node, $family, $change]) {
            $label = self::familyLabel($family);
            $health = $node->health($family);
            if ($change === self::BECAME_HEALTHY) {
                $message = isset($before[$family][$node->nodeId])
                    ? "节点 {$label} 健康检查恢复（故障期间一直保留在 DNS 中）"
                    : "节点 {$label} 健康检查恢复，重新加入 DNS";
                $this->events->info('node_recovered', $message, $node->pool, $node->nodeId);
                continue;
            }
            if (!isset($after[$family][$node->nodeId])) {
                $what = '从 DNS 摘除';
            } elseif (self::anyHealthy($after[$family], $family)) {
                // Route 53：整池故障在 pool_failover_after 内保留最后一批节点
                $what = '整池故障宽限期内暂时保留在 DNS 中';
            } else {
                $what = '但没有健康的节点可以承接，暂时保留在 DNS 中';
            }
            $this->events->warning(
                'node_unhealthy',
                "节点 {$label} 连续 {$health->fails} 次健康检查失败，{$what}：{$health->error}",
                $node->pool,
                $node->nodeId,
                ['ip' => $node->ip($family)]
            );
        }
    }

    /**
     * @return array<string,array<string,Node>> 当前选出发布的节点：协议族 => 节点 ID => 节点
     */
    private function selected(int $now): array
    {
        $result = [];
        foreach ($this->selector->select($now) as $family => $pools) {
            foreach ($pools as $nodes) {
                foreach ($nodes as $node) {
                    $result[$family][$node->nodeId] = $node;
                }
            }
        }
        return $result;
    }

    /**
     * @param Node[] $nodes
     */
    private static function anyHealthy(array $nodes, string $family): bool
    {
        foreach ($nodes as $node) {
            if ($node->health($family)->healthy) {
                return true;
            }
        }
        return false;
    }

    /**
     * 记录每个池、每个协议族整池不可用的开始时间，NodeSelector 据此决定何时撤下整个池。
     *
     * @param Node[] $nodes
     * @param string[] $families
     */
    private function trackPools(array $nodes, array $families, int $now): void
    {
        foreach ($this->config->pools as $name => $pool) {
            $state = $this->pools->get($name);
            $changed = false;
            foreach ($families as $family) {
                $candidates = 0;
                $healthy = 0;
                foreach ($nodes as $node) {
                    if ($node->pool === $name && in_array($node->state, self::SERVING_STATES, true) && $node->ip($family) !== null) {
                        $candidates++;
                        if ($node->health($family)->healthy) {
                            $healthy++;
                        }
                    }
                }
                $down = $candidates > 0 && $healthy === 0;
                $label = self::familyLabel($family);
                if ($down && $state->downSince($family) === null) {
                    $state->setDownSince($family, $now);
                    $changed = true;
                    $this->events->critical('pool_down', $this->poolDownMessage($name, $family, $candidates, $nodes), $name);
                } elseif (!$down && $state->downSince($family) !== null) {
                    $downFor = $now - (int) $state->downSince($family);
                    $state->setDownSince($family, null);
                    $changed = true;
                    $this->events->info('pool_recovered', "池 {$label} 已恢复（中断 {$downFor} 秒）", $name);
                }
            }
            if ($changed) {
                $this->pools->saveHealth($state);
            }
        }
    }

    /**
     * 整池故障时 DNS 会怎样：其他池有健康节点时撤下该池（Route 53 等 pool_failover_after，Cloudflare 立即）；
     * 没有时全部保留（NodeSelector 不会让域名没有记录）。
     *
     * @param Node[] $nodes 本轮探测的节点
     */
    private function poolDownMessage(string $pool, string $family, int $candidates, array $nodes): string
    {
        $message = "池内全部 {$candidates} 个在线节点 " . self::familyLabel($family) . ' 均不健康，';
        foreach ($nodes as $node) {
            if ($node->pool !== $pool
                && isset($this->config->pools[$node->pool])
                && in_array($node->state, self::SERVING_STATES, true)
                && $node->ip($family) !== null
                && $node->health($family)->healthy
            ) {
                return $message . ($this->config->dns->isCloudflare()
                    ? '已从 DNS 撤下，由其他池的节点承接'
                    : "若 {$this->config->healthCheck->poolFailoverAfter} 秒内未恢复，将把该区域从 DNS 撤下，流量转到其他区域");
            }
        }
        return $message . '没有其他池可以承接，暂时保留在 DNS 中';
    }

    private static function familyLabel(string $family): string
    {
        return $family === Node::FAMILY_V6 ? 'IPv6' : 'IPv4';
    }
}
