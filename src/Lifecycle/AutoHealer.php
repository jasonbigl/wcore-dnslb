<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 自动修复：
 * - 在线节点持续不健康超过 unhealthy_for：先建替补（节点进入 REPLACING），替补上线后再排空旧节点；
 *   旧节点在替补上线前恢复，则取消替补；
 * - 在线节点的实例丢失：立即一对一补建。
 *
 * 防风暴：健康检查冻结时不处理；所有在线节点中不健康比例超过 max_unhealthy_ratio 视为系统性故障，只告警；
 * 每小时替换次数有上限。替补不计入池的 max。
 */
final class AutoHealer
{
    public const EVENT_REPLACE = 'autoheal_replace';
    /** 同类"无法修复"告警的最小间隔 */
    private const NOTICE_INTERVAL = 3600;

    private Config $config;
    private Provisioner $provisioner;
    private Reconciler $reconciler;
    private NodeRepository $nodes;
    private PoolRepository $pools;
    private EventRepository $eventLog;
    private EventRecorder $events;
    private Meta $meta;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        Provisioner $provisioner,
        Reconciler $reconciler,
        NodeRepository $nodes,
        PoolRepository $pools,
        EventRepository $eventLog,
        EventRecorder $events,
        Meta $meta,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->provisioner = $provisioner;
        $this->reconciler = $reconciler;
        $this->nodes = $nodes;
        $this->pools = $pools;
        $this->eventLog = $eventLog;
        $this->events = $events;
        $this->meta = $meta;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * @param Node[] $lost 本轮丢失的在线节点（Reconciler::advanceNodes() 的返回值）
     */
    public function run(array $lost): void
    {
        // 进行中的替换（包括手动替换）无论是否开启自动修复都要收尾
        $this->finishReplacements();
        if (!$this->config->autoheal->enabled) {
            return;
        }

        foreach ($lost as $node) {
            $replacement = $this->nodes->findReplacementOf($node->nodeId);
            if ($replacement !== null) {
                // 替换途中实例丢失：替补已经在途，不再重复建机
                $this->logger->info("Lost node {$node->nodeId} already has replacement {$replacement->nodeId}");
                continue;
            }
            if (!isset($this->config->pools[$node->pool]) || $this->poolPaused($node->pool)) {
                continue;
            }
            if (!$this->withinHourlyLimit()) {
                return;
            }
            $this->replace($node, '实例丢失', false);
        }

        if ($this->meta->get('frozen_v4') !== null || $this->meta->get('frozen_v6') !== null) {
            $this->logger->info('Health checks are frozen, skipping auto-heal of unhealthy nodes');
            return;
        }

        $now = $this->clock->now();
        $serving = $this->nodes->byStates([Node::IN_SERVICE, Node::REPLACING]);
        $unhealthyNow = array_filter($serving, function (Node $node): bool {
            return $this->unhealthyFamily($node) !== null;
        });
        $ratio = $serving === [] ? 0.0 : count($unhealthyNow) / count($serving);
        if ($ratio > $this->config->autoheal->maxUnhealthyRatio) {
            $this->notice('autoheal_systemic', sprintf(
                '%d/%d 个在线节点不健康，超过 %d%%，判定为系统性故障，暂停自动替换。请检查应用是否整体故障',
                count($unhealthyNow),
                count($serving),
                (int) round($this->config->autoheal->maxUnhealthyRatio * 100)
            ));
            return;
        }

        foreach ($unhealthyNow as $node) {
            if ($node->state !== Node::IN_SERVICE || !isset($this->config->pools[$node->pool])) {
                continue;
            }
            $family = (string) $this->unhealthyFamily($node);
            $since = (int) $node->health($family)->since;
            if ($now - $since < $this->config->autoheal->unhealthyFor) {
                continue;
            }
            $replacement = $this->nodes->findReplacementOf($node->nodeId);
            if ($replacement !== null) {
                // 上次建替补后进程在保存前退出：沿用已建出的替补，不再重复建机
                $this->markReplacing($node, $replacement, false);
                $this->logger->info("Reusing replacement {$replacement->nodeId} created earlier for {$node->nodeId}");
                continue;
            }
            if ($this->poolPaused($node->pool)) {
                continue;
            }
            if (!$this->withinHourlyLimit()) {
                return;
            }
            $label = $family === Node::FAMILY_V6 ? 'IPv6' : 'IPv4';
            $this->replace($node, "{$label} 持续不健康 " . ($now - $since) . " 秒（{$node->health($family)->error}）", true);
        }
    }

    /**
     * 处理进行中的替换：替补上线 → 排空旧节点；替补失败 → 旧节点恢复为在线，稍后重试；旧节点先恢复 → 取消替补。
     */
    private function finishReplacements(): void
    {
        foreach ($this->nodes->byStates([Node::REPLACING]) as $node) {
            $replacement = $node->replacedBy === null ? null : $this->nodes->find($node->replacedBy);
            $now = $this->clock->now();

            if ($replacement !== null && $replacement->state === Node::IN_SERVICE) {
                $this->reconciler->drain($node, "替补 {$replacement->nodeId} 已上线");
                continue;
            }
            if ($replacement === null || !in_array($replacement->state, [Node::PROVISIONING, Node::BOOTING, Node::WARMING], true)) {
                $node->replacedBy = null;
                $node->replaceManual = false;
                $node->transition(Node::IN_SERVICE, $now);
                $this->nodes->save($node, $now);
                $this->events->warning('replace_failed', '替补未能上线，节点恢复为在线' . ($replacement === null ? '' : "（替补 {$replacement->nodeId}：{$replacement->lastError}）"), $node->pool, $node->nodeId);
                continue;
            }
            if (!$node->replaceManual && $this->unhealthyFamily($node) === null) {
                $this->reconciler->cancel($replacement, "被替换的节点 {$node->nodeId} 已恢复健康，取消替补");
                $node->replacedBy = null;
                $node->transition(Node::IN_SERVICE, $now);
                $this->nodes->save($node, $now);
            }
        }
    }

    /**
     * 手动替换在线节点（replace 命令）：先建替补，替补上线后排空旧节点；不受自动修复的开关和限频约束。
     *
     * @return Node 替补节点（建机失败时状态为 FAILED）
     */
    public function replaceManually(Node $node): Node
    {
        if ($node->state !== Node::IN_SERVICE) {
            throw new \RuntimeException("Only in-service nodes can be replaced ({$node->nodeId} is {$node->state})");
        }
        if (!isset($this->config->pools[$node->pool])) {
            throw new \RuntimeException("Pool {$node->pool} is not in the configuration");
        }
        // 上次手动替换建出替补后进程在保存前退出时，沿用那台替补
        $replacement = $this->nodes->findReplacementOf($node->nodeId)
            ?? $this->provisioner->create($this->config->pools[$node->pool], $node->nodeId);
        if ($replacement->state !== Node::FAILED) {
            $this->markReplacing($node, $replacement, true);
            $this->events->info('manual_replace', "手动替换，替补 {$replacement->nodeId}", $node->pool, $node->nodeId);
        }
        return $replacement;
    }

    private function replace(Node $node, string $reason, bool $keepUntilReplaced): void
    {
        $pool = $this->config->pools[$node->pool];
        $replacement = $this->provisioner->create($pool, $node->nodeId);
        if ($replacement->state === Node::FAILED) {
            $this->reconciler->recordStartupFailure($node->pool);
            $this->events->warning('autoheal_failed', "{$reason}，但创建替补失败：{$replacement->lastError}", $node->pool, $node->nodeId);
            return;
        }
        $this->events->warning(self::EVENT_REPLACE, "{$reason}，已创建替补 {$replacement->nodeId}", $node->pool, $node->nodeId);
        if ($keepUntilReplaced) {
            $this->markReplacing($node, $replacement, false);
        }
    }

    /**
     * 旧节点进入 REPLACING，替补上线后再排空。
     */
    private function markReplacing(Node $node, Node $replacement, bool $manual): void
    {
        $now = $this->clock->now();
        $node->replacedBy = $replacement->nodeId;
        $node->replaceManual = $manual;
        $node->transition(Node::REPLACING, $now);
        $this->nodes->save($node, $now);
    }

    private function poolPaused(string $pool): bool
    {
        if ($this->pools->get($pool)->isPaused($this->clock->now())) {
            $this->logger->warning("Pool {$pool} is paused, not creating replacements");
            return true;
        }
        return false;
    }

    private function withinHourlyLimit(): bool
    {
        $limit = $this->config->autoheal->maxReplacementsPerHour;
        if ($this->eventLog->countSince(self::EVENT_REPLACE, $this->clock->now() - 3600) >= $limit) {
            $this->notice('autoheal_limited', "过去一小时已自动替换 {$limit} 次，达到上限，暂停自动替换");
            return false;
        }
        return true;
    }

    /**
     * 节点不健康的协议族（优先 IPv4）；健康时返回 null。
     */
    private function unhealthyFamily(Node $node): ?string
    {
        if (!$node->v4->healthy) {
            return Node::FAMILY_V4;
        }
        if ($this->config->dns->ipv6 && $node->ipv6 !== null && !$node->v6->healthy) {
            return Node::FAMILY_V6;
        }
        return null;
    }

    /**
     * 每小时最多记录一次的告警。
     */
    private function notice(string $type, string $message): void
    {
        $now = $this->clock->now();
        $last = $this->meta->get("notice_{$type}");
        if ($last !== null && $now - (int) $last < self::NOTICE_INTERVAL) {
            $this->logger->warning($message);
            return;
        }
        $this->meta->set("notice_{$type}", (string) $now);
        $this->events->critical($type, $message);
    }
}
