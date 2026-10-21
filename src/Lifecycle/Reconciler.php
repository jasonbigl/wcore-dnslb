<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Dns\DnsSync;
use Wcore\Dnslb\Metrics\MetricException;
use Wcore\Dnslb\Metrics\MetricRegistry;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\ProviderRegistry;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * tick 中推进节点生命周期，并让每个池的节点数保持在 min ~ max 之间。
 *
 * 安全原则：
 * - 查询云厂商失败时，不把任何节点当作丢失；
 * - 刚创建的实例可能暂时查不到（EC2 最终一致性），超过 MISSING_GRACE 仍查不到才视为丢失；
 * - 排空的节点必须确认已不在 DNS 中才删除机器。
 */
final class Reconciler
{
    private const MISSING_GRACE = 600;
    /** 建机请求结果未知时，至少等待多久再查找 / 重试 */
    private const RECOVER_DELAY = 30;
    /**
     * 同一建机方式结果未知超过这么久、且按节点 ID 查不到实例时，换下一种方式。
     * 期间每个 tick 查找一次并幂等重发，至少连续两次（间隔一分钟）查不到才换，远超 EC2 按标签查询的延迟（通常几秒）。
     */
    private const UNKNOWN_FALLBACK_AFTER = 120;
    /** 计入池容量的状态 */
    private const ACTIVE_STATES = [Node::PROVISIONING, Node::BOOTING, Node::WARMING, Node::IN_SERVICE];
    private const SERVING_STATES = [Node::IN_SERVICE, Node::REPLACING];

    private Config $config;
    private ProviderRegistry $providers;
    private Provisioner $provisioner;
    private NodeRepository $nodes;
    private PoolRepository $pools;
    private DnsSync $dnsSync;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;
    private ?MetricRegistry $metrics;

    /** @var Node[] 本轮丢失的在线节点 */
    private array $lost = [];
    /** @var array<string,float>|null 排空空闲指标（每轮按需查询一次）；null 表示尚未查询 */
    private ?array $idleValues = null;

    /** @var array<string,array<string,Instance>|null> 云厂商 => 实例 ID => 实例；null 表示本轮查询失败 */
    private array $instances = [];
    /** @var array<string,true>|null 当前在 DNS 中的节点 ID；null 表示尚未读取 */
    private ?array $nodesInDns = null;
    private bool $dnsUnavailable = false;

    public function __construct(
        Config $config,
        ProviderRegistry $providers,
        Provisioner $provisioner,
        NodeRepository $nodes,
        PoolRepository $pools,
        DnsSync $dnsSync,
        EventRecorder $events,
        Clock $clock,
        Logger $logger,
        ?MetricRegistry $metrics = null
    ) {
        $this->config = $config;
        $this->providers = $providers;
        $this->provisioner = $provisioner;
        $this->nodes = $nodes;
        $this->pools = $pools;
        $this->dnsSync = $dnsSync;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
        $this->metrics = $metrics;
    }

    /**
     * 推进节点状态并维持池规模（tick 中这两步之间还会运行自动修复，见 TickRunner）。
     */
    public function run(): void
    {
        $this->advanceNodes();
        $this->enforcePoolSizes();
    }

    /**
     * 推进所有节点的生命周期。
     *
     * @return Node[] 本轮丢失（实例被删除 / 停止）的在线节点，已被撤下
     */
    public function advanceNodes(): array
    {
        $this->nodesInDns = null;
        $this->dnsUnavailable = false;
        $this->idleValues = null;
        $this->lost = [];
        $live = $this->nodes->live();
        $this->instances = $this->listInstances($live);

        foreach ($live as $node) {
            try {
                $this->advance($node);
            } catch (\Throwable $e) {
                $this->logger->error("Failed to advance node {$node->nodeId} ({$node->state}): {$e->getMessage()}", ['exception' => get_class($e)]);
                $node->lastError = $e->getMessage();
                $this->nodes->save($node, $this->clock->now());
            }
        }
        return $this->lost;
    }

    /**
     * 取消一个尚未上线的节点（删除实例）。
     */
    public function cancel(Node $node, string $reason): void
    {
        $this->fail($node, 'node_cancelled', $reason, 'info');
    }

    /**
     * 从 DNS 摘除并开始排空。
     */
    public function drain(Node $node, string $reason): void
    {
        $node->transition(Node::DRAINING, $this->clock->now());
        $node->dnsRemovedAt = null;
        $this->nodes->save($node, $this->clock->now());
        $drain = $this->config->drain;
        $when = $drain->idleQuery !== null
            ? "确认摘除后，流量降到阈值以下（至少 {$drain->min} 秒）或 {$drain->max} 秒后删除机器"
            : "确认摘除后 {$drain->max} 秒删除机器";
        $this->events->info('node_draining', "从 DNS 摘除并开始排空（{$reason}），{$when}", $node->pool, $node->nodeId);
    }

    /**
     * 缩容时的选择顺序：先选不健康的节点，再选发布中等待替换的旧节点，再选用备选方式建出的节点，再按创建时间从新到旧。
     *
     * @param Node[] $nodes
     * @return Node[]
     */
    public static function scaleInOrder(array $nodes, bool $ipv6): array
    {
        usort($nodes, static function (Node $a, Node $b) use ($ipv6): int {
            return [!$b->isHealthy($ipv6), $b->outdated, $b->launchIndex, $b->createdAt, $b->nodeId]
                <=> [!$a->isHealthy($ipv6), $a->outdated, $a->launchIndex, $a->createdAt, $a->nodeId];
        });
        return $nodes;
    }

    /**
     * 缩容要排空的节点：按缩容顺序最多选 $count 台，剩下的在线节点、以及其中健康的节点都不少于 $min。
     * 有节点故障或正在替换时，按台数算出的余量并不真实，不能因此排空健康节点。
     *
     * @param Node[] $inService IN_SERVICE 节点
     * @return Node[]
     */
    public static function scaleInTargets(array $inService, int $count, int $min, bool $ipv6): array
    {
        $count = min($count, count($inService) - $min);
        $healthy = count(array_filter($inService, static function (Node $node) use ($ipv6): bool {
            return $node->isHealthy($ipv6);
        }));
        $targets = [];
        foreach (self::scaleInOrder($inService, $ipv6) as $node) {
            if (count($targets) >= $count) {
                break;
            }
            if ($node->isHealthy($ipv6)) {
                // 不健康的节点排在前面，之后都是健康节点
                if ($healthy <= $min) {
                    break;
                }
                $healthy--;
            }
            $targets[] = $node;
        }
        return $targets;
    }

    private function advance(Node $node): void
    {
        switch ($node->state) {
            case Node::PROVISIONING:
                $this->advanceProvisioning($node);
                break;
            case Node::BOOTING:
                $this->advanceBooting($node);
                break;
            case Node::WARMING:
                $this->advanceWarming($node);
                break;
            case Node::IN_SERVICE:
            case Node::REPLACING:
                $this->checkServing($node);
                break;
            case Node::DRAINING:
                $this->advanceDraining($node);
                break;
            case Node::FAILED:
            case Node::TERMINATING:
                $this->terminate($node);
                break;
        }
    }

    private function advanceProvisioning(Node $node): void
    {
        $now = $this->clock->now();
        if ($node->instanceId !== null) {
            $node->transition(Node::BOOTING, $now);
            $this->nodes->save($node, $now);
            return;
        }
        if ($node->stateAge($now) < self::RECOVER_DELAY) {
            return;
        }
        // 上次建机请求结果未知：先确认实例是否已经创建
        $found = $this->providers->get($node->provider)->findByNodeId($node->providerRegion, $node->nodeId);
        if ($found !== null) {
            $this->provisioner->adopt($node, $found);
            $this->logger->info("Found instance {$found->id} for {$node->nodeId} created by an earlier request");
            return;
        }
        $pool = $this->config->pools[$node->pool] ?? null;
        if ($pool === null) {
            $this->fail($node, 'node_cancelled', '所属池已从配置删除，取消建机', 'info');
            return;
        }
        if ($now - $node->createdAt > $this->config->warmupTimeout) {
            $this->fail($node, 'launch_failed', '建机超时：' . ($node->lastError ?? '未知原因'));
            return;
        }
        // 默认用同一方式重发（同一区域内按节点 ID 去重，不会重复建机），直到得到明确结果
        $from = $node->launchIndex;
        $unknownFor = $now - ($node->launchStartedAt ?? $node->createdAt);
        if ($unknownFor >= self::UNKNOWN_FALLBACK_AFTER && $from + 1 < count($pool->launch)) {
            $this->logger->warning("Launch of {$node->nodeId} via {$node->provider}/{$node->providerRegion} still unknown after {$unknownFor}s and no instance found; trying the next launch method");
            $from++;
        }
        $this->provisioner->launch($node, $pool, $from);
    }

    private function advanceBooting(Node $node): void
    {
        $now = $this->clock->now();
        [$known, $instance] = $this->lookup($node);
        if (!$known) {
            return;
        }
        if ($instance === null) {
            if ($now - $node->createdAt > self::MISSING_GRACE) {
                $this->fail($node, 'node_lost', '实例启动过程中消失（已被删除）');
            }
            return;
        }
        if ($instance->status === Instance::GONE || $instance->status === Instance::STOPPED) {
            $this->fail($node, 'node_lost', "实例启动过程中被停止或删除（{$instance->status}）");
            return;
        }

        $changed = self::updateAddresses($node, $instance);
        if ($instance->status === Instance::RUNNING && $node->ipv4 !== null && (!$this->config->dns->ipv6 || $node->ipv6 !== null)) {
            $node->transition(Node::WARMING, $now);
            $this->nodes->save($node, $now);
            $this->logger->info("Node {$node->nodeId} is running ({$node->ipv4}" . ($node->ipv6 !== null ? ", {$node->ipv6}" : '') . '), warming up');
            return;
        }
        if ($now - $node->createdAt > $this->config->warmupTimeout) {
            $waiting = $node->ipv4 === null ? '公网 IPv4 地址' : ($this->config->dns->ipv6 && $node->ipv6 === null ? 'IPv6 地址（子网是否已启用 IPv6？）' : '实例启动');
            $this->warmupFailed($node, "等待{$waiting}超时");
            return;
        }
        if ($changed) {
            $this->nodes->save($node, $now);
        }
    }

    private function advanceWarming(Node $node): void
    {
        $now = $this->clock->now();
        [$known, $instance] = $this->lookup($node);
        if ($known && $instance === null && $now - $node->createdAt > self::MISSING_GRACE) {
            $this->fail($node, 'node_lost', '实例在预热过程中消失（已被删除）');
            return;
        }
        if ($instance !== null && ($instance->status === Instance::GONE || $instance->status === Instance::STOPPED)) {
            $this->fail($node, 'node_lost', "实例在预热过程中被停止或删除（{$instance->status}）");
            return;
        }

        $healthy = $node->v4->healthy && (!$this->config->dns->ipv6 || $node->v6->healthy);
        if ($healthy) {
            $node->transition(Node::IN_SERVICE, $now);
            $this->nodes->save($node, $now);
            $state = $this->pools->get($node->pool);
            if ($state->warmupFailures > 0) {
                $state->warmupFailures = 0;
                $this->pools->save($state);
            }
            $this->events->info('node_in_service', '节点通过健康检查，加入 DNS（从创建到上线 ' . ($now - $node->createdAt) . ' 秒）', $node->pool, $node->nodeId);
            return;
        }
        if ($now - $node->createdAt > $this->config->warmupTimeout) {
            $error = $node->v4->error ?? $node->v6->error ?? ($node->probedAt === null ? '健康检查尚未执行（Dnslb::health() 是否在定时运行？）' : '健康检查未通过');
            $this->warmupFailed($node, "健康检查一直未通过：{$error}");
        }
    }

    private function checkServing(Node $node): void
    {
        $now = $this->clock->now();
        [$known, $instance] = $this->lookup($node);
        if (!$known || ($instance === null && $now - $node->createdAt <= self::MISSING_GRACE)) {
            return;
        }
        if ($instance === null || $instance->status === Instance::GONE) {
            $this->lost($node, '实例已不存在（被删除或宿主机故障）');
            return;
        }
        if ($instance->status === Instance::STOPPED) {
            $this->lost($node, '实例已停止');
            return;
        }
        if (self::updateAddresses($node, $instance)) {
            $this->nodes->save($node, $now);
            $this->logger->warning("Address of {$node->nodeId} changed", ['ipv4' => $node->ipv4, 'ipv6' => $node->ipv6]);
        }
    }

    private function advanceDraining(Node $node): void
    {
        $now = $this->clock->now();
        [$known, $instance] = $this->lookup($node);
        if ($known && ($instance === null || $instance->status === Instance::GONE) && $now - $node->createdAt > self::MISSING_GRACE) {
            $node->transition(Node::TERMINATED, $now);
            $this->nodes->save($node, $now);
            $this->events->info('node_terminated', '实例已不存在，排空结束', $node->pool, $node->nodeId);
            return;
        }
        // 每个 tick 都确认节点已不在 DNS 中：DNS 同步失败时节点可能仍在解析结果中，排空期间记录也可能被外部加回
        // （例如人工恢复了旧记录）。确认摘除后才开始计算排空时长，记录重新出现时重新计时，读不到 DNS 时只延后
        $inDns = $this->inDns($node);
        if ($inDns === null) {
            return;
        }
        if ($inDns) {
            if ($node->dnsRemovedAt !== null) {
                $node->dnsRemovedAt = null;
                $this->nodes->save($node, $now);
                $this->events->warning('drain_restarted', '排空期间节点重新出现在 DNS 中（记录被外部加回？），重新计算排空时间', $node->pool, $node->nodeId);
            }
            $this->logger->warning("Node {$node->nodeId} is draining but still in DNS; drain timer starts once it is removed");
            return;
        }
        if ($node->dnsRemovedAt === null) {
            $node->dnsRemovedAt = $now;
            $this->nodes->save($node, $now);
        }
        if (!$this->drainComplete($node, $now)) {
            return;
        }
        $this->terminate($node);
    }

    /**
     * 删除实例并标记为 TERMINATED。失败时保持 TERMINATING，下次 tick 重试。
     *
     * 还没有实例 ID 时按节点 ID 查找；建机结果未知时实例可能刚建出、暂时查不到（EC2 最终一致性），
     * 所以进入 TERMINATING 后 MISSING_GRACE 秒内查不到都保持 TERMINATING，下次 tick 再查。
     */
    private function terminate(Node $node): void
    {
        $now = $this->clock->now();
        if ($node->state !== Node::TERMINATING) {
            $node->transition(Node::TERMINATING, $now);
            $this->nodes->save($node, $now);
        }
        $provider = $this->providers->get($node->provider);
        $instanceId = $node->instanceId;
        if ($instanceId === null) {
            $found = $provider->findByNodeId($node->providerRegion, $node->nodeId);
            $instanceId = $found === null ? null : $found->id;
            if ($instanceId === null && $node->stateAge($now) < self::MISSING_GRACE) {
                $this->logger->debug("No instance found for {$node->nodeId} yet; checking again before marking it terminated");
                return;
            }
        }
        if ($instanceId !== null) {
            $provider->terminate($node->providerRegion, $instanceId);
        }
        $node->transition(Node::TERMINATED, $this->clock->now());
        $this->nodes->save($node, $this->clock->now());
        $this->events->info('node_terminated', $instanceId === null ? '节点已结束（没有实例需要删除）' : "已删除实例 {$instanceId}", $node->pool, $node->nodeId);
    }

    /**
     * 排空是否完成（从确认摘除时算起）：满 drain.max；或配置了 drain.idle 时，满 drain.min 且流量低于阈值。
     */
    private function drainComplete(Node $node, int $now): bool
    {
        $drain = $this->config->drain;
        $age = $now - (int) $node->dnsRemovedAt;
        if ($age >= $drain->max) {
            return true;
        }
        if ($drain->idleQuery === null || $drain->idleSource === null || $age < $drain->min || $this->metrics === null) {
            return false;
        }
        if ($this->idleValues === null) {
            try {
                $this->idleValues = $this->metrics->queryByNode($drain->idleSource, $drain->idleQuery);
            } catch (MetricException $e) {
                $this->logger->warning("Drain idle query failed, waiting for drain.max: {$e->getMessage()}");
                $this->idleValues = [];
            }
        }
        // 查不到该节点的数据时视为未知，继续等待
        $value = $this->idleValues[$node->nodeId] ?? null;
        if ($value !== null && $value < $drain->idleBelow) {
            $this->logger->info("Node {$node->nodeId} is idle ({$value} < {$drain->idleBelow}) after {$age}s of draining");
            return true;
        }
        return false;
    }

    public function enforcePoolSizes(): void
    {
        foreach ($this->config->pools as $name => $pool) {
            try {
                $this->enforcePoolSize($pool);
            } catch (\Throwable $e) {
                $this->logger->error("Failed to maintain pool {$name}: {$e->getMessage()}", ['exception' => get_class($e)]);
            }
        }

        // 已从配置删除的池：排空在线节点，取消尚未上线的节点
        foreach ($this->nodes->live() as $node) {
            if (isset($this->config->pools[$node->pool])) {
                continue;
            }
            try {
                if (in_array($node->state, self::SERVING_STATES, true)) {
                    $this->drain($node, '所属池已从配置删除');
                } elseif (in_array($node->state, [Node::PROVISIONING, Node::BOOTING, Node::WARMING], true)) {
                    $this->fail($node, 'node_cancelled', '所属池已从配置删除，取消建机', 'info');
                }
            } catch (\Throwable $e) {
                $this->logger->error("Failed to retire node {$node->nodeId}: {$e->getMessage()}", ['exception' => get_class($e)]);
            }
        }
    }

    private function enforcePoolSize(PoolConfig $pool): void
    {
        $now = $this->clock->now();
        $active = array_values(array_filter($this->nodes->liveInPool($pool->name), static function (Node $node): bool {
            return in_array($node->state, self::ACTIVE_STATES, true);
        }));

        $deficit = $pool->min - count($active);
        if ($deficit > 0) {
            if ($this->pools->get($pool->name)->isPaused($now)) {
                $this->logger->warning("Pool {$pool->name} is below min ({$pool->min}) but paused; not creating nodes", ['active' => count($active)]);
            } else {
                $this->logger->info("Pool {$pool->name} is below min, creating {$deficit} node(s)", ['active' => count($active), 'min' => $pool->min]);
                for ($i = 0; $i < $deficit; $i++) {
                    $node = $this->provisioner->create($pool);
                    if ($node->state === Node::FAILED) {
                        // 所有建机方式都失败：计入连续失败，本轮不再继续尝试
                        $this->recordStartupFailure($pool->name);
                        $this->terminate($node);
                        break;
                    }
                }
            }
        }

        $excess = count($active) - $pool->max;
        if ($excess > 0 && !$this->pools->get($pool->name)->manualPaused) {
            $this->shrink($pool, $active, $excess, "节点数超过 max（{$pool->max}）");
        }
    }

    /**
     * 减少池中的节点：先取消尚未上线的（取消替补时一并排空被替换的节点），再按缩容顺序排空在线节点。
     *
     * @param Node[] $active
     */
    private function shrink(PoolConfig $pool, array $active, int $count, string $reason): void
    {
        $pending = array_filter($active, static function (Node $node): bool {
            return $node->state !== Node::IN_SERVICE;
        });
        $serving = array_filter($active, static function (Node $node): bool {
            return $node->state === Node::IN_SERVICE;
        });
        foreach (self::scaleInOrder($pending, $this->config->dns->ipv6) as $node) {
            if ($count <= 0) {
                return;
            }
            $this->fail($node, 'node_cancelled', "{$reason}，取消尚未上线的节点", 'info');
            // 替补和被它替换的节点只算一台，一起移除：只取消替补的话，旧节点会恢复为在线并再次建替补
            $replaced = $node->replacementFor === null ? null : $this->nodes->find($node->replacementFor);
            if ($replaced !== null && $replaced->state === Node::REPLACING) {
                $this->drain($replaced, $reason);
            }
            $count--;
        }
        foreach (self::scaleInOrder($serving, $this->config->dns->ipv6) as $node) {
            if ($count <= 0) {
                return;
            }
            $this->drain($node, $reason);
            $count--;
        }
    }

    private function warmupFailed(Node $node, string $reason): void
    {
        $this->fail($node, 'warmup_timeout', "超过 {$this->config->warmupTimeout} 秒仍未上线：{$reason}");
        $this->recordStartupFailure($node->pool);
    }

    /**
     * 新节点未能上线（建机失败或预热超时）。连续达到上限时暂停该池建机，避免反复建机烧钱。
     */
    public function recordStartupFailure(string $pool): void
    {
        $state = $this->pools->get($pool);
        $state->warmupFailures++;
        $limit = $this->config->autoheal->maxWarmupFailures;
        if ($state->warmupFailures >= $limit && !$state->isPaused($this->clock->now())) {
            $state->pausedUntil = $this->clock->now() + $this->config->autoheal->pauseFor;
            $this->events->critical(
                'pool_paused',
                "连续 {$state->warmupFailures} 台新节点未能上线，暂停该池建机 {$this->config->autoheal->pauseFor} 秒。请检查云厂商配额、镜像、user-data 和健康检查配置",
                $pool
            );
        }
        $this->pools->save($state);
    }

    /**
     * 在线节点的实例丢失或停止：立即撤下并删除（替补由 min 保障或自动修复补上）。
     */
    private function lost(Node $node, string $reason): void
    {
        $this->events->warning('node_lost', $reason . '，撤下节点', $node->pool, $node->nodeId);
        $node->lastError = $reason;
        $this->lost[] = $node;
        $this->terminate($node);
    }

    private function fail(Node $node, string $type, string $message, string $level = 'warning'): void
    {
        $now = $this->clock->now();
        $node->lastError = $message;
        $node->transition(Node::FAILED, $now);
        $this->nodes->save($node, $now);
        if ($level === 'info') {
            $this->events->info($type, $message, $node->pool, $node->nodeId);
        } else {
            $this->events->warning($type, $message, $node->pool, $node->nodeId);
        }
        $this->terminate($node);
    }

    /**
     * @return array{0:bool,1:?Instance} [是否可信, 实例]；云厂商查询失败或节点没有实例 ID 时第一个值为 false
     */
    private function lookup(Node $node): array
    {
        if ($node->instanceId === null) {
            return [false, null];
        }
        $instances = $this->instances[$node->provider] ?? null;
        if ($instances === null) {
            return [false, null];
        }
        return [true, $instances[$node->instanceId] ?? null];
    }

    /**
     * 按云厂商批量列出实例（每个云厂商 / 区域一次请求）。
     *
     * @param Node[] $nodes
     * @return array<string,array<string,Instance>|null>
     */
    private function listInstances(array $nodes): array
    {
        $regions = [];
        foreach ($nodes as $node) {
            if ($node->instanceId !== null) {
                $regions[$node->provider][$node->providerRegion] = true;
            }
        }
        $result = [];
        foreach ($regions as $provider => $providerRegions) {
            try {
                $result[$provider] = [];
                foreach ($this->providers->get($provider)->listManaged($this->config->service, array_keys($providerRegions)) as $instance) {
                    $result[$provider][$instance->id] = $instance;
                }
            } catch (\Throwable $e) {
                $result[$provider] = null;
                $this->logger->warning("Cannot list instances on {$provider}, skipping its nodes this tick: {$e->getMessage()}");
            }
        }
        return $result;
    }

    /**
     * @return bool|null 节点是否仍在 DNS 中；读不到 DNS 记录时返回 null（每个 tick 只读一次）
     */
    private function inDns(Node $node): ?bool
    {
        if ($this->dnsUnavailable) {
            return null;
        }
        if ($this->nodesInDns === null) {
            try {
                $this->nodesInDns = $this->dnsSync->publishedNodeIds();
            } catch (\Throwable $e) {
                $this->dnsUnavailable = true;
                $this->logger->warning("Cannot read DNS records, postponing drain checks: {$e->getMessage()}");
                return null;
            }
        }
        return isset($this->nodesInDns[$node->nodeId]);
    }

    /**
     * @return bool 地址是否有变化
     */
    private static function updateAddresses(Node $node, Instance $instance): bool
    {
        $changed = false;
        if ($instance->ipv4 !== null && $instance->ipv4 !== $node->ipv4) {
            $node->ipv4 = $instance->ipv4;
            $changed = true;
        }
        if ($instance->ipv6 !== null && $instance->ipv6 !== $node->ipv6) {
            $node->ipv6 = $instance->ipv6;
            $changed = true;
        }
        return $changed;
    }
}
