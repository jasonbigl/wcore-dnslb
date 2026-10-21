<?php

namespace Wcore\Dnslb;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\ConfigException;
use Wcore\Dnslb\Dns\SyncResult;
use Wcore\Dnslb\Operations\Checker;
use Wcore\Dnslb\Operations\CheckReport;
use Wcore\Dnslb\Operations\NodeOperations;
use Wcore\Dnslb\Operations\OperationException;
use Wcore\Dnslb\Operations\OrphanCleanupResult;
use Wcore\Dnslb\Operations\RebuildResult;
use Wcore\Dnslb\Operations\RolloutResult;
use Wcore\Dnslb\Operations\ScaleResult;
use Wcore\Dnslb\Operations\StateRebuilder;
use Wcore\Dnslb\Operations\StatusReport;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Support\Logger;
use Wcore\Dnslb\Support\PsrLoggerAdapter;

/**
 * dnslb 的入口，例如在 Laravel 中：
 *
 *   $lb = Dnslb::fromConfigFile('/etc/dnslb/myapp.php', ['logger' => Log::channel('dnslb')]);
 *   $lb->tick();                           // 调度器每分钟调用
 *   $lb->health();                         // 调度器每分钟调用（约 55 秒，需 runInBackground）
 *   $result = $lb->scale('dallas', 1);     // 手动操作返回结构化结果
 *
 * 每次调用都使用新的内部实例，可以在常驻进程中长期持有本对象。
 * 修改节点的方法持有 tick 锁，不会与 tick 或其他手动操作同时执行。
 * 参数错误抛出 InvalidArgumentException，操作无法执行抛出 OperationException，外部服务异常原样抛出。
 */
final class Dnslb
{
    private Config $config;
    private array $options;

    /**
     * @param array $options 可选：
     *   - logger：Wcore\Dnslb\Support\Logger 或任意 PSR-3 风格的 logger（如 Laravel 的 Log::channel('dnslb')）；
     *             不传时按配置中的 log 段输出到标准输出或日志文件
     *   - transport / clock / database：替换默认组件（测试用）
     *   - providers：[名称 => ProviderInterface]，替换云厂商实现（测试用）
     */
    public function __construct(Config $config, array $options = [])
    {
        $this->config = $config;
        $this->options = $options;
    }

    /**
     * @throws ConfigException
     */
    public static function fromConfigFile(string $path, array $options = []): self
    {
        return new self(Config::load($path), $options);
    }

    /**
     * 直接使用配置数组（例如 Laravel 的 config('dnslb.myapp')）。
     *
     * @param string $baseDir 配置中相对路径（state、user_data.template 等）的基准目录
     * @throws ConfigException
     */
    public static function fromConfigArray(array $config, string $baseDir, array $options = []): self
    {
        return new self(Config::fromArray($config, $baseDir), $options);
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * 推进节点生命周期、自动修复、维持池规模、按指标扩缩容、同步 DNS、发送通知和心跳。每分钟调度一次。
     *
     * @param bool $dryRun 在状态库的内存副本上演练，只记录日志，不修改任何东西
     * @return bool 是否执行（上一个 tick 未结束时返回 false）
     */
    public function tick(bool $dryRun = false): bool
    {
        $app = $this->app('tick', $dryRun);
        return $app->tickRunner()->run($app->isDryRun());
    }

    /**
     * 健康检查并同步 DNS。每分钟调度一次，进程内每 health_check.interval 秒一轮，运行约 $duration 秒。
     *
     * @return int 完成的轮数
     */
    public function health(int $duration = 55, bool $once = false, bool $dryRun = false): int
    {
        $app = $this->app('health', $dryRun);
        return $app->healthRunner()->run($duration, $once, $app->isDryRun());
    }

    /**
     * 各池、节点状态和最近事件（只读本地状态库）。
     *
     * @see StatusReport::collect() 返回结构
     */
    public function status(): array
    {
        return (new StatusReport($this->app('status')))->collect();
    }

    /**
     * 校验配置，并用只读接口测试 DNS（Route 53 / Cloudflare）、云厂商、指标、SES、探测机出口。
     */
    public function check(): CheckReport
    {
        return (new Checker($this->app('check')))->run();
    }

    /**
     * 接管对外域名上现有的 A / AAAA / CNAME 记录，解析不中断。
     * Route 53：在同一个原子批次中用 latency 记录替换；Cloudflare：先添加节点记录，传播后再删除旧记录。
     * 建议先用 $dryRun = true 预览 changes，确认后再执行。
     *
     * @throws \RuntimeException 还没有任何池有在线节点时拒绝接管
     */
    public function takeover(bool $dryRun = false): SyncResult
    {
        $app = $this->app('takeover', $dryRun);
        return $app->dnsSync()->takeover($app->isDryRun());
    }

    /**
     * 手动扩容（$delta > 0，受 max 限制）或缩容（$delta < 0，受 min 限制，立即同步 DNS）。
     * 自动扩缩容之后仍可能按指标调整，需要保持手动结果时先 pause()。
     */
    public function scale(string $pool, int $delta, bool $dryRun = false): ScaleResult
    {
        return $this->withNodes('scale', $dryRun, static function (NodeOperations $ops) use ($pool, $delta): ScaleResult {
            return $ops->scale($pool, $delta);
        });
    }

    /**
     * 排空在线节点：立即从 DNS 摘除，排空后删除机器。
     *
     * @return Node 排空中的节点
     * @throws OperationException 节点不在线
     */
    public function drain(string $nodeId, bool $dryRun = false): Node
    {
        return $this->withNodes('drain', $dryRun, static function (NodeOperations $ops) use ($nodeId): Node {
            return $ops->drain($nodeId);
        });
    }

    /**
     * 替换在线节点：先建新机器，上线后再排空旧节点。
     *
     * @return Node 替补节点
     * @throws OperationException 节点不在线，或替补建机失败
     */
    public function replace(string $nodeId, bool $dryRun = false): Node
    {
        return $this->withNodes('replace', $dryRun, static function (NodeOperations $ops) use ($nodeId): Node {
            return $ops->replace($nodeId);
        });
    }

    /**
     * 发布新版本：用新机器逐台替换现有节点（新机器开机时部署新版本），由之后的 tick 逐步完成。
     * 调用前先确认新版本已经可以被开机部署拿到。
     *
     * 每个池同时最多替换 $batch 台，替补上线后才排空旧节点。进度见 status() 中池的 rollout。
     * 连续多台新节点未能上线时自动停止并告警；再次调用会重新开始（所有现有节点重新标记为旧版本）。
     *
     * @param string|null $pool null 表示所有池
     * @throws OperationException 池因连续启动失败处于自动暂停（排查原因后先 resume()）
     */
    public function rollout(?string $pool = null, int $batch = 1, bool $dryRun = false): RolloutResult
    {
        return $this->withNodes('rollout', $dryRun, static function (NodeOperations $ops) use ($pool, $batch): RolloutResult {
            return $ops->rollout($pool, $batch);
        });
    }

    /**
     * 停止发布。已经开始的替换会继续完成，其余旧节点保持不变。$pool 为 null 时作用于所有池。
     */
    public function cancelRollout(?string $pool = null): void
    {
        $this->withNodes('cancel-rollout', false, static function (NodeOperations $ops) use ($pool): void {
            $ops->cancelRollout($pool);
        });
    }

    /**
     * 暂停自动增删机器（维护时使用；健康检查和 DNS 摘除照常），进行中的发布也会等待。$pool 为 null 时作用于所有池。
     */
    public function pause(?string $pool = null): void
    {
        $this->withNodes('pause', false, static function (NodeOperations $ops) use ($pool): void {
            $ops->setPaused($pool, true);
        });
    }

    /**
     * 恢复自动增删机器，同时解除连续启动失败造成的自动暂停。$pool 为 null 时作用于所有池。
     */
    public function resume(?string $pool = null): void
    {
        $this->withNodes('resume', false, static function (NodeOperations $ops) use ($pool): void {
            $ops->setPaused($pool, false);
        });
    }

    /**
     * 删除孤儿实例：云上带本服务标签、但连续 15 分钟没有节点引用的实例（tick 每 10 分钟检查一次并告警）。
     * 调用时会重新检查一次。默认只预览（$dryRun = true），确认列表后再传 false 删除。
     * 状态库从备份恢复后不要直接删除：备份之后创建的正常节点也会被当成孤儿，应先 rebuildState()。
     */
    public function cleanupOrphans(bool $dryRun = true): OrphanCleanupResult
    {
        return $this->withNodes('cleanup-orphans', $dryRun, static function (NodeOperations $ops): OrphanCleanupResult {
            return $ops->cleanupOrphans();
        });
    }

    /**
     * 根据云厂商实例和 DNS 记录重建状态库（只补充缺失的节点）。
     */
    public function rebuildState(bool $dryRun = false): RebuildResult
    {
        $app = $this->app('rebuild-state', $dryRun);
        return $app->exclusive(static function () use ($app): RebuildResult {
            return (new StateRebuilder($app))->rebuild();
        });
    }

    /**
     * 底层组件（高级用法），例如 $lb->app()->nodes()->live()。
     *
     * @param string $name 日志前缀中的名称
     */
    public function app(string $name = 'php', bool $dryRun = false): Application
    {
        $overrides = array_intersect_key($this->options, array_flip(['transport', 'clock', 'database']));
        $logger = $this->options['logger'] ?? null;
        if ($logger !== null) {
            $overrides['logger'] = $logger instanceof Logger ? $logger : new PsrLoggerAdapter($logger, "[dnslb:{$name}] ");
        }
        $app = new Application($this->config, $name, $dryRun, $overrides);
        foreach ($this->options['providers'] ?? [] as $providerName => $provider) {
            $app->providers()->set($providerName, $provider);
        }
        return $app;
    }

    /**
     * 持有 tick 锁执行节点操作。
     *
     * @return mixed
     */
    private function withNodes(string $name, bool $dryRun, callable $operation)
    {
        $app = $this->app($name, $dryRun);
        return $app->exclusive(static function () use ($app, $operation) {
            return $operation(new NodeOperations($app));
        });
    }
}
