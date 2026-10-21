<?php

namespace Wcore\Dnslb;

use Wcore\Dnslb\Aws\Route53\Route53Client;
use Wcore\Dnslb\Aws\Ses\SesClient;
use Wcore\Dnslb\Cloudflare\CloudflareClient;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Dns\CloudflareSync;
use Wcore\Dnslb\Dns\DnsSync;
use Wcore\Dnslb\Dns\EmptyPublishGuard;
use Wcore\Dnslb\Dns\NodeSelector;
use Wcore\Dnslb\Dns\Route53Planner;
use Wcore\Dnslb\Dns\Route53Sync;
use Wcore\Dnslb\Health\HealthChecker;
use Wcore\Dnslb\Health\HealthRunner;
use Wcore\Dnslb\Health\Prober;
use Wcore\Dnslb\Heartbeat\Heartbeat;
use Wcore\Dnslb\Http\CurlTransport;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Lifecycle\AutoHealer;
use Wcore\Dnslb\Lifecycle\OrphanTracker;
use Wcore\Dnslb\Lifecycle\Provisioner;
use Wcore\Dnslb\Lifecycle\Reconciler;
use Wcore\Dnslb\Lifecycle\Rollout;
use Wcore\Dnslb\Lifecycle\TickRunner;
use Wcore\Dnslb\Lifecycle\UserDataRenderer;
use Wcore\Dnslb\Metrics\MetricRegistry;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\Notify\Notifier;
use Wcore\Dnslb\Provider\ProviderRegistry;
use Wcore\Dnslb\Scaling\Scaler;
use Wcore\Dnslb\State\AlertLog;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\OrphanRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;
use Wcore\Dnslb\Support\StreamLogger;
use Wcore\Dnslb\Support\SystemClock;

/**
 * 按配置组装各组件（按需创建，同一实例内复用）。
 * 在项目代码里也可以直接使用，例如：
 *
 *   $app = new Application(Config::load('/etc/dnslb/web.php'));
 *   $app->healthRunner()->run(55, false, false);
 */
final class Application
{
    private Config $config;
    private string $command;
    private bool $dryRun;
    private array $instances = [];

    /**
     * @param array $overrides 替换默认组件（测试用），可用键：transport、clock、logger、database
     */
    public function __construct(Config $config, string $command = '', bool $dryRun = false, array $overrides = [])
    {
        $this->config = $config;
        $this->command = $command;
        $this->dryRun = $dryRun || $config->dryRun;
        $this->instances = $overrides;
        // 演练模式下即使传入了数据库，也只在它的内存副本上操作
        if ($this->dryRun && isset($overrides['database'])) {
            $this->instances['database'] = $overrides['database']->cloneToMemory();
        }
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function clock(): Clock
    {
        return $this->instances['clock'] ??= new SystemClock();
    }

    public function logger(): Logger
    {
        return $this->instances['logger'] ??= new StreamLogger($this->config->logFile, $this->config->logLevel, $this->command);
    }

    public function transport(): Transport
    {
        return $this->instances['transport'] ??= new CurlTransport();
    }

    /**
     * 状态库。演练模式下使用一份内存副本：可以照常计算，但不会修改真实状态。
     */
    public function database(): Database
    {
        if (!isset($this->instances['database'])) {
            $path = $this->config->statePath;
            if (!$this->dryRun) {
                $this->instances['database'] = Database::open($path);
            } elseif ($path !== ':memory:' && is_file($path)) {
                // 只读打开：不迁移、不切换日志模式，真实状态库保持原样
                $this->instances['database'] = Database::openReadOnly($path)->cloneToMemory();
            } else {
                $this->instances['database'] = Database::open(':memory:');
            }
        }
        return $this->instances['database'];
    }

    public function nodes(): NodeRepository
    {
        return $this->instances[NodeRepository::class] ??= new NodeRepository($this->database());
    }

    public function pools(): PoolRepository
    {
        return $this->instances[PoolRepository::class] ??= new PoolRepository($this->database());
    }

    public function events(): EventRepository
    {
        return $this->instances[EventRepository::class] ??= new EventRepository($this->database());
    }

    public function meta(): Meta
    {
        return $this->instances[Meta::class] ??= new Meta($this->database());
    }

    public function alertLog(): AlertLog
    {
        return $this->instances[AlertLog::class] ??= new AlertLog($this->database());
    }

    public function eventRecorder(): EventRecorder
    {
        return $this->instances[EventRecorder::class] ??= new EventRecorder($this->events(), $this->clock(), $this->logger());
    }

    public function route53(): Route53Client
    {
        return $this->instances[Route53Client::class] ??= new Route53Client($this->transport(), $this->config->aws, $this->clock());
    }

    public function cloudflare(): CloudflareClient
    {
        return $this->instances[CloudflareClient::class] ??= new CloudflareClient($this->transport(), (string) $this->config->cloudflareToken, $this->clock());
    }

    public function nodeSelector(): NodeSelector
    {
        return $this->instances[NodeSelector::class] ??= new NodeSelector($this->config, $this->nodes(), $this->pools());
    }

    /**
     * 按 dns.provider 选择 Route53Sync 或 CloudflareSync。
     */
    public function dnsSync(): DnsSync
    {
        if (!isset($this->instances[DnsSync::class])) {
            $guard = new EmptyPublishGuard($this->config->dns->name, $this->meta(), $this->eventRecorder(), $this->clock(), $this->logger());
            $this->instances[DnsSync::class] = $this->config->dns->isCloudflare()
                ? new CloudflareSync($this->config, $this->cloudflare(), $this->nodeSelector(), $this->lock('dns'), $guard, $this->clock(), $this->logger())
                : new Route53Sync(
                    $this->config,
                    $this->route53(),
                    new Route53Planner($this->config, $this->nodeSelector()),
                    $this->lock('dns'),
                    $guard,
                    $this->clock(),
                    $this->logger()
                );
        }
        return $this->instances[DnsSync::class];
    }

    public function prober(): Prober
    {
        return $this->instances[Prober::class] ??= new Prober($this->transport(), $this->config->healthCheck);
    }

    public function healthChecker(): HealthChecker
    {
        return $this->instances[HealthChecker::class] ??= new HealthChecker(
            $this->config,
            $this->prober(),
            $this->nodes(),
            $this->pools(),
            $this->meta(),
            $this->eventRecorder(),
            $this->clock(),
            $this->logger()
        );
    }

    public function healthRunner(): HealthRunner
    {
        return $this->instances[HealthRunner::class] ??= new HealthRunner(
            $this->healthChecker(),
            $this->dnsSync(),
            $this->lock('health'),
            $this->clock(),
            $this->logger(),
            $this->config->healthCheck->interval,
            $this->heartbeat()
        );
    }

    public function providers(): ProviderRegistry
    {
        return $this->instances[ProviderRegistry::class] ??= new ProviderRegistry(
            $this->config,
            $this->transport(),
            $this->clock(),
            $this->dryRun ? $this->logger() : null
        );
    }

    public function userDataRenderer(): UserDataRenderer
    {
        return $this->instances[UserDataRenderer::class] ??= new UserDataRenderer($this->config->userData);
    }

    public function provisioner(): Provisioner
    {
        return $this->instances[Provisioner::class] ??= new Provisioner(
            $this->config,
            $this->providers(),
            $this->nodes(),
            $this->userDataRenderer(),
            $this->eventRecorder(),
            $this->clock(),
            $this->logger()
        );
    }

    public function reconciler(): Reconciler
    {
        return $this->instances[Reconciler::class] ??= new Reconciler(
            $this->config,
            $this->providers(),
            $this->provisioner(),
            $this->nodes(),
            $this->pools(),
            $this->dnsSync(),
            $this->eventRecorder(),
            $this->clock(),
            $this->logger(),
            $this->metrics()
        );
    }

    public function metrics(): MetricRegistry
    {
        return $this->instances[MetricRegistry::class] ??= new MetricRegistry($this->config, $this->transport());
    }

    public function autoHealer(): AutoHealer
    {
        return $this->instances[AutoHealer::class] ??= new AutoHealer(
            $this->config,
            $this->provisioner(),
            $this->reconciler(),
            $this->nodes(),
            $this->pools(),
            $this->events(),
            $this->eventRecorder(),
            $this->meta(),
            $this->clock(),
            $this->logger()
        );
    }

    public function rollout(): Rollout
    {
        return $this->instances[Rollout::class] ??= new Rollout(
            $this->config,
            $this->provisioner(),
            $this->reconciler(),
            $this->nodes(),
            $this->pools(),
            $this->eventRecorder(),
            $this->meta(),
            $this->clock(),
            $this->logger()
        );
    }

    public function scaler(): Scaler
    {
        return $this->instances[Scaler::class] ??= new Scaler(
            $this->config,
            $this->metrics(),
            $this->nodes(),
            $this->pools(),
            $this->provisioner(),
            $this->reconciler(),
            $this->eventRecorder(),
            $this->clock(),
            $this->logger()
        );
    }

    public function ses(): SesClient
    {
        return $this->instances[SesClient::class] ??= new SesClient($this->transport(), $this->config->aws, $this->clock());
    }

    /**
     * 未配置 notify 时为 null。
     */
    public function notifier(): ?Notifier
    {
        if ($this->config->notify === null) {
            return null;
        }
        return $this->instances[Notifier::class] ??= new Notifier(
            $this->config,
            $this->ses(),
            $this->events(),
            $this->alertLog(),
            $this->clock(),
            $this->logger()
        );
    }

    public function heartbeat(): Heartbeat
    {
        return $this->instances[Heartbeat::class] ??= new Heartbeat($this->config->heartbeat, $this->transport(), $this->config->service, $this->logger());
    }

    public function orphans(): OrphanRepository
    {
        return $this->instances[OrphanRepository::class] ??= new OrphanRepository($this->database());
    }

    public function orphanTracker(): OrphanTracker
    {
        return $this->instances[OrphanTracker::class] ??= new OrphanTracker(
            $this->config,
            $this->providers(),
            $this->nodes(),
            $this->orphans(),
            $this->meta(),
            $this->eventRecorder(),
            $this->clock(),
            $this->logger()
        );
    }

    public function tickRunner(): TickRunner
    {
        return $this->instances[TickRunner::class] ??= new TickRunner(
            $this->reconciler(),
            $this->autoHealer(),
            $this->scaler(),
            $this->rollout(),
            $this->orphanTracker(),
            $this->dnsSync(),
            $this->notifier(),
            $this->heartbeat(),
            $this->metrics(),
            $this->nodes(),
            $this->events(),
            $this->eventRecorder(),
            $this->lock('tick'),
            $this->clock(),
            $this->logger()
        );
    }

    /**
     * 持有 tick 锁执行回调，供修改节点的手动命令使用，避免与正在运行的 tick 冲突。
     *
     * @return mixed 回调的返回值
     * @throws \RuntimeException 等待超时
     */
    public function exclusive(callable $callback, int $waitSeconds = 60)
    {
        $lock = $this->lock('tick');
        $deadline = $this->clock()->now() + $waitSeconds;
        while (!$lock->acquire(false)) {
            if ($this->clock()->now() >= $deadline) {
                throw new \RuntimeException("A tick is still running after {$waitSeconds}s, try again later");
            }
            $this->clock()->sleep(500000);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * 进程锁，文件放在状态库旁边，例如 /var/lib/dnslb/web.sqlite.health.lock。
     */
    public function lock(string $name): FileLock
    {
        $key = "lock.{$name}";
        if (!isset($this->instances[$key])) {
            $base = $this->config->statePath === ':memory:'
                ? sys_get_temp_dir() . "/dnslb-{$this->config->service}"
                : $this->config->statePath;
            $this->instances[$key] = new FileLock("{$base}.{$name}.lock");
        }
        return $this->instances[$key];
    }
}
