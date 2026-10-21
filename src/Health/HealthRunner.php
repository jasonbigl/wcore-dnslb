<?php

namespace Wcore\Dnslb\Health;

use Wcore\Dnslb\Dns\DnsSync;
use Wcore\Dnslb\Heartbeat\Heartbeat;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * health 命令的主循环：cron 每分钟启动一次，进程内每 interval 秒执行一轮，
 * 运行约 duration 秒后退出。期望 DNS 发生变化时（以及每次启动的第一轮）执行 DnsSync。
 */
final class HealthRunner
{
    /** 上一个进程还没退出时，最多等待多少秒拿锁 */
    private const LOCK_WAIT_SECONDS = 15;

    private HealthChecker $checker;
    private DnsSync $dnsSync;
    private FileLock $lock;
    private Clock $clock;
    private Logger $logger;
    private int $interval;
    private ?Heartbeat $heartbeat;

    public function __construct(
        HealthChecker $checker,
        DnsSync $dnsSync,
        FileLock $lock,
        Clock $clock,
        Logger $logger,
        int $interval,
        ?Heartbeat $heartbeat = null
    ) {
        $this->checker = $checker;
        $this->dnsSync = $dnsSync;
        $this->lock = $lock;
        $this->clock = $clock;
        $this->logger = $logger;
        $this->interval = $interval;
        $this->heartbeat = $heartbeat;
    }

    /**
     * @param int  $duration 运行总时长（秒）
     * @param bool $once     只执行一轮
     * @return int 成功完成的轮数
     */
    public function run(int $duration, bool $once, bool $dryRun): int
    {
        if (!$this->acquireLock()) {
            $this->logger->warning('Another health process is still running, exiting');
            return 0;
        }

        try {
            $start = $this->clock->now();
            $lastFingerprint = null;
            $completed = 0;
            while (true) {
                $roundStart = $this->clock->now();
                try {
                    $summary = $this->checker->runRound();
                    $fingerprint = $this->dnsSync->desiredFingerprint($this->clock->now());
                    if ($fingerprint !== $lastFingerprint) {
                        $this->dnsSync->sync($dryRun);
                        $lastFingerprint = $fingerprint;
                    }
                    $completed++;
                    $this->logger->info('Health round', [
                        'targets' => $summary->targets,
                        'failed' => $summary->failed,
                        'down' => $summary->becameUnhealthy,
                        'up' => $summary->becameHealthy,
                    ] + ($summary->frozenFamilies ? ['frozen' => $summary->frozenFamilies] : [])
                      + ($summary->skippedFamilies ? ['canary_down' => $summary->skippedFamilies] : []));
                } catch (\Throwable $e) {
                    // 本轮失败（例如 DNS 服务商暂时不可用）：下一轮重试，指纹未更新所以会再次同步
                    $this->logger->error('Health round failed: ' . $e->getMessage(), ['exception' => get_class($e)]);
                }

                $next = $roundStart + $this->interval;
                if ($once || $next >= $start + $duration) {
                    if ($completed > 0 && !$dryRun && $this->heartbeat !== null) {
                        $this->heartbeat->send('health');
                    }
                    return $completed;
                }
                $wait = $next - $this->clock->now();
                if ($wait > 0) {
                    $this->clock->sleep($wait * 1000000);
                }
            }
        } finally {
            $this->lock->release();
        }
    }

    private function acquireLock(): bool
    {
        $deadline = $this->clock->now() + self::LOCK_WAIT_SECONDS;
        while (!$this->lock->acquire(false)) {
            if ($this->clock->now() >= $deadline) {
                return false;
            }
            $this->clock->sleep(500000);
        }
        return true;
    }
}
