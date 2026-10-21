<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\State\PoolState;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 发布新版本：用新机器逐台替换发布开始时已存在的节点（新机器开机时部署新版本）。
 *
 * start() 把池中现有节点标记为旧版本；之后每次 tick，每个池最多同时替换 batch 台：
 * 先建替补，替补上线后再排空旧节点，在线节点数不会减少。
 * - 手动暂停或健康检查冻结时等待；
 * - 连续启动失败导致池自动暂停时（新版本可能有问题），停止发布并告警，剩余旧节点继续服务；
 * - 发布期间扩容、自动修复新建的节点都是新版本，不会被替换；缩容优先排空旧节点。
 */
final class Rollout
{
    /** 旧节点处于这些状态时还需要替换（尚未上线的旧节点等上线后再替换） */
    public const REMAINING_STATES = [Node::PROVISIONING, Node::BOOTING, Node::WARMING, Node::IN_SERVICE, Node::REPLACING];

    private Config $config;
    private Provisioner $provisioner;
    private Reconciler $reconciler;
    private NodeRepository $nodes;
    private PoolRepository $pools;
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
        $this->events = $events;
        $this->meta = $meta;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * 开始发布：把池中现有节点标记为旧版本。发布进行中再次调用会重新开始，此前替换上的节点也会再次替换。
     *
     * @return Node[] 标记为旧版本的节点；池中没有节点时为空，不开始发布
     */
    public function start(string $pool, int $batch): array
    {
        $now = $this->clock->now();
        $nodes = $this->nodes->byStates(self::REMAINING_STATES, $pool);
        if ($nodes === []) {
            return [];
        }
        foreach ($nodes as $node) {
            $node->outdated = true;
            $this->nodes->save($node, $now);
        }
        $state = $this->pools->get($pool);
        $restart = $state->rolloutStartedAt !== null;
        $state->rolloutStartedAt = $now;
        $state->rolloutBatch = $batch;
        $state->rolloutTotal = count($nodes);
        $this->pools->save($state);
        $this->events->info('rollout_started', sprintf(
            '%s发布：%d 台节点待替换，每次替换 %d 台',
            $restart ? '重新开始' : '开始',
            count($nodes),
            $batch
        ), $pool);
        return $nodes;
    }

    /**
     * 停止发布。已经开始的替换会继续完成，其余旧节点保持不变。
     *
     * @return bool 是否有进行中的发布
     */
    public function cancel(string $pool): bool
    {
        $state = $this->pools->get($pool);
        if ($state->rolloutStartedAt === null) {
            return false;
        }
        $remaining = $this->remaining($pool);
        $replacing = count(array_filter($remaining, static function (Node $node): bool {
            return $node->state === Node::REPLACING;
        }));
        $this->finish($state);
        $this->events->info('rollout_cancelled', sprintf(
            '手动停止发布：%d 台正在替换的会继续完成，其余 %d 台旧节点不再替换',
            $replacing,
            count($remaining) - $replacing
        ), $pool);
        return true;
    }

    /**
     * tick 中推进各池的发布。
     */
    public function run(): void
    {
        foreach ($this->pools->all() as $name => $state) {
            if ($state->rolloutStartedAt === null) {
                continue;
            }
            try {
                $this->advance($state);
            } catch (\Throwable $e) {
                $this->logger->error("Rollout of pool {$name} failed: {$e->getMessage()}", ['exception' => get_class($e)]);
            }
        }
    }

    private function advance(PoolState $state): void
    {
        $now = $this->clock->now();
        $pool = $this->config->pools[$state->pool] ?? null;
        if ($pool === null) {
            $this->finish($state);
            $this->events->info('rollout_cancelled', '所属池已从配置删除，停止发布', $state->pool);
            return;
        }
        $remaining = $this->remaining($state->pool);
        if ($remaining === []) {
            $this->finish($state);
            $this->events->info('rollout_completed', sprintf(
                '发布完成：%d 台旧节点已全部下线，用时 %d 分钟',
                $state->rolloutTotal,
                (int) round(($now - (int) $state->rolloutStartedAt) / 60)
            ), $state->pool);
            return;
        }
        if ($state->manualPaused) {
            $this->logger->info("Pool {$state->pool} is manually paused, rollout is waiting", ['remaining' => count($remaining)]);
            return;
        }
        if ($state->isPaused($now)) {
            // 发布开始时不允许自动暂停（见 NodeOperations::rollout），因此这是发布期间连续启动失败造成的
            $this->finish($state);
            $this->events->critical('rollout_aborted', sprintf(
                '连续 %d 台新节点未能上线，停止发布，%d 台旧节点继续服务。请检查新版本和开机部署，修复后先 resume() 再重新 rollout()',
                $state->warmupFailures,
                count($remaining)
            ), $state->pool);
            return;
        }
        if ($this->meta->get('frozen_v4') !== null || $this->meta->get('frozen_v6') !== null) {
            $this->logger->info("Health checks are frozen, rollout of pool {$state->pool} is waiting");
            return;
        }

        $replacing = count(array_filter($remaining, static function (Node $node): bool {
            return $node->state === Node::REPLACING;
        }));
        $slots = $state->rolloutBatch - $replacing;
        foreach ($remaining as $node) {
            if ($slots <= 0) {
                return;
            }
            if ($node->state !== Node::IN_SERVICE) {
                continue;
            }
            // 上次建替补后进程在保存前退出时，沿用那台替补
            $replacement = $this->nodes->findReplacementOf($node->nodeId) ?? $this->provisioner->create($pool, $node->nodeId);
            if ($replacement->state === Node::FAILED) {
                $this->reconciler->recordStartupFailure($state->pool);
                $this->events->warning('rollout_replace_failed', "发布：创建替补失败，稍后重试（{$replacement->lastError}）", $state->pool, $node->nodeId);
                return;
            }
            $node->replacedBy = $replacement->nodeId;
            $node->replaceManual = true;
            $node->transition(Node::REPLACING, $now);
            $this->nodes->save($node, $now);
            $this->events->info('rollout_replace', "发布：已创建替补 {$replacement->nodeId}，上线后排空本节点", $state->pool, $node->nodeId);
            $slots--;
        }
    }

    /**
     * @return Node[] 还需要替换的旧节点（按创建时间从旧到新）
     */
    private function remaining(string $pool): array
    {
        return array_values(array_filter($this->nodes->byStates(self::REMAINING_STATES, $pool), static function (Node $node): bool {
            return $node->outdated;
        }));
    }

    private function finish(PoolState $state): void
    {
        $state->rolloutStartedAt = null;
        $state->rolloutTotal = 0;
        $this->pools->save($state);
        $this->nodes->clearOutdated($state->pool);
    }
}
