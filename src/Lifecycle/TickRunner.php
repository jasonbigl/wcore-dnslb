<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Dns\DnsSync;
use Wcore\Dnslb\Heartbeat\Heartbeat;
use Wcore\Dnslb\Metrics\MetricRegistry;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\Notify\Notifier;
use Wcore\Dnslb\Scaling\Scaler;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * tick 命令的完整流程：
 * 推进节点生命周期 → 自动修复 → 维持 min / max → 按指标扩缩容 → 推进发布 → 同步 DNS → 孤儿实例巡检（每 10 分钟）
 * → 发送通知 → 清理旧数据 → 心跳。
 * 每一步独立处理异常，某一步失败不影响后续步骤；有步骤失败时不发送心跳。
 */
final class TickRunner
{
    /** 上一个 tick 还没结束时，最多等待多少秒 */
    private const LOCK_WAIT_SECONDS = 30;
    private const EVENT_RETENTION = 30 * 86400;
    private const TERMINATED_RETENTION = 7 * 86400;

    private Reconciler $reconciler;
    private AutoHealer $autoHealer;
    private Scaler $scaler;
    private Rollout $rollout;
    private OrphanTracker $orphans;
    private DnsSync $dnsSync;
    private ?Notifier $notifier;
    private Heartbeat $heartbeat;
    private MetricRegistry $metrics;
    private NodeRepository $nodes;
    private EventRepository $eventLog;
    private EventRecorder $events;
    private FileLock $lock;
    private Clock $clock;
    private Logger $logger;
    private int $failedSteps = 0;

    public function __construct(
        Reconciler $reconciler,
        AutoHealer $autoHealer,
        Scaler $scaler,
        Rollout $rollout,
        OrphanTracker $orphans,
        DnsSync $dnsSync,
        ?Notifier $notifier,
        Heartbeat $heartbeat,
        MetricRegistry $metrics,
        NodeRepository $nodes,
        EventRepository $eventLog,
        EventRecorder $events,
        FileLock $lock,
        Clock $clock,
        Logger $logger
    ) {
        $this->reconciler = $reconciler;
        $this->autoHealer = $autoHealer;
        $this->scaler = $scaler;
        $this->rollout = $rollout;
        $this->orphans = $orphans;
        $this->dnsSync = $dnsSync;
        $this->notifier = $notifier;
        $this->heartbeat = $heartbeat;
        $this->metrics = $metrics;
        $this->nodes = $nodes;
        $this->eventLog = $eventLog;
        $this->events = $events;
        $this->lock = $lock;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * @return bool 是否执行（拿不到锁时返回 false）
     */
    public function run(bool $dryRun): bool
    {
        $deadline = $this->clock->now() + self::LOCK_WAIT_SECONDS;
        while (!$this->lock->acquire(false)) {
            if ($this->clock->now() >= $deadline) {
                $this->logger->warning('Previous tick is still running, skipping this one');
                return false;
            }
            $this->clock->sleep(500000);
        }

        try {
            $this->failedSteps = 0;
            $this->metrics->clearCache();
            $lost = $this->step('advance nodes', function (): array {
                return $this->reconciler->advanceNodes();
            }) ?? [];
            $this->step('auto-heal', function () use ($lost): void {
                $this->autoHealer->run($lost);
            });
            $this->step('maintain pool sizes', function (): void {
                $this->reconciler->enforcePoolSizes();
            });
            $this->step('scale', function (): void {
                $this->scaler->run();
            });
            $this->step('rollout', function (): void {
                $this->rollout->run();
            });
            $this->step('sync DNS', function () use ($dryRun): void {
                try {
                    $this->dnsSync->sync($dryRun);
                } catch (\Throwable $e) {
                    $this->events->warning('dns_sync_failed', 'DNS 同步失败：' . $e->getMessage());
                    throw $e;
                }
            });
            $this->step('find orphan instances', function (): void {
                $this->orphans->track();
            });

            if (!$dryRun) {
                if ($this->notifier !== null) {
                    $this->step('notify', function (): void {
                        $this->notifier->flush();
                    });
                }
                $this->step('housekeeping', function (): void {
                    $now = $this->clock->now();
                    $this->eventLog->purge($now - self::EVENT_RETENTION);
                    $this->nodes->purgeTerminated($now - self::TERMINATED_RETENTION);
                });
                // 心跳表示本次 tick 正常完成：有步骤失败时不发送，让外部监控能发现持续的故障
                if ($this->failedSteps === 0) {
                    $this->heartbeat->send('tick');
                } else {
                    $this->logger->warning("{$this->failedSteps} tick step(s) failed, not sending heartbeat");
                }
            }
            return true;
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @return mixed 步骤的返回值；失败时返回 null
     */
    private function step(string $name, callable $callback)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            $this->failedSteps++;
            $this->logger->error("Tick step \"{$name}\" failed: {$e->getMessage()}", ['exception' => get_class($e)]);
            return null;
        }
    }
}
