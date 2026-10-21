<?php

namespace Wcore\Dnslb\Config;

use Wcore\Dnslb\Aws\Credentials;

/**
 * 一个服务（一个域名）的完整配置。配置文件是返回数组的 PHP 文件。
 */
final class Config
{
    /** 服务名、池名：小写字母数字，单个连字符分隔，最长 20 个字符 */
    public const NAME_PATTERN = '/^(?=.{1,20}$)[a-z0-9]+(-[a-z0-9]+)*$/';

    private const TOP_LEVEL_KEYS = [
        'service', 'state', 'log', 'dry_run', 'aws', 'cloudflare', 'providers', 'dns', 'health_check', 'warmup',
        'drain', 'autoheal', 'metric_sources', 'scaling', 'user_data', 'notify', 'heartbeat', 'pools',
    ];

    public string $service;
    /** SQLite 文件的绝对路径 */
    public string $statePath;
    public string $logLevel;
    public ?string $logFile;
    public bool $dryRun;
    /** AWS 密钥（Route 53、EC2、SES 使用）；都不使用时可以不配置，此时为空字符串 */
    public Credentials $aws;
    /** Cloudflare API 令牌（dns.provider 为 cloudflare 时必填） */
    public ?string $cloudflareToken = null;
    /** @var array<string,ProviderConfig> */
    public array $providers = [];
    public DnsConfig $dns;
    public HealthCheckConfig $healthCheck;
    public int $warmupTimeout;
    public DrainConfig $drain;
    public AutohealConfig $autoheal;
    /** @var array<string,MetricSourceConfig> */
    public array $metricSources = [];
    public UserDataConfig $userData;
    public ?NotifyConfig $notify = null;
    public ?HeartbeatConfig $heartbeat = null;
    /** @var array<string,PoolConfig> */
    public array $pools = [];

    /**
     * 加载配置文件。相对路径（state、模板、日志文件）以配置文件所在目录为基准。
     *
     * @throws ConfigException
     */
    public static function load(string $file): self
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new ConfigException(["config file not readable: {$file}"]);
        }
        $data = (static function (string $__file) {
            return require $__file;
        })($file);
        if (!is_array($data)) {
            throw new ConfigException(["config file must return an array: {$file}"]);
        }
        return self::fromArray($data, dirname((string) realpath($file)));
    }

    /**
     * @throws ConfigException
     */
    public static function fromArray(array $data, string $baseDir): self
    {
        $errors = new ErrorBag();
        $root = new Section($data, '', $errors);
        $root->allowOnly(self::TOP_LEVEL_KEYS);

        $c = new self();
        $c->service = $root->string('service', Section::REQUIRED, self::NAME_PATTERN);
        $c->statePath = self::resolvePath($root->string('state'), $baseDir);
        $c->dryRun = $root->bool('dry_run', false);

        $log = $root->sectionOrEmpty('log');
        $log->allowOnly(['level', 'file']);
        $c->logLevel = $log->enum('level', ['debug', 'info', 'warning', 'error'], 'info');
        $logFile = $log->optionalString('file');
        $c->logFile = $logFile === null ? null : self::resolvePath($logFile, $baseDir);

        foreach ($root->namedSections('providers', true) as $name => $section) {
            $c->providers[$name] = ProviderConfig::fromSection($name, $section);
        }

        $c->dns = DnsConfig::fromSection($root->sectionOrEmpty('dns', true));
        $c->healthCheck = HealthCheckConfig::fromSection($root->sectionOrEmpty('health_check'), $c->dns->wildcard ? null : $c->dns->name);

        $warmup = $root->sectionOrEmpty('warmup');
        $warmup->allowOnly(['timeout']);
        $c->warmupTimeout = $warmup->int('timeout', 900, 60);

        $c->drain = DrainConfig::fromSection($root->sectionOrEmpty('drain'));
        $c->autoheal = AutohealConfig::fromSection($root->sectionOrEmpty('autoheal'));

        foreach ($root->namedSections('metric_sources') as $name => $section) {
            $c->metricSources[$name] = MetricSourceConfig::fromSection($name, $section);
        }

        $scalingSection = $root->sectionOrEmpty('scaling');
        $scaling = ScalingConfig::fromSection($scalingSection);

        $c->userData = UserDataConfig::fromSection($root->sectionOrEmpty('user_data', true), $baseDir);

        $notify = $root->section('notify');
        if ($notify !== null) {
            $c->notify = NotifyConfig::fromSection($notify, $c->service);
        }
        $heartbeat = $root->section('heartbeat');
        if ($heartbeat !== null) {
            $c->heartbeat = HeartbeatConfig::fromSection($heartbeat);
        }

        foreach ($root->namedSections('pools', true) as $name => $section) {
            $c->pools[$name] = PoolConfig::fromSection($name, $section, $c->providers, $scaling, $scalingSection->toArray(), $c->dns->provider);
        }

        $aws = $root->section('aws');
        if ($aws === null) {
            $users = $c->awsUsers();
            if ($users !== []) {
                $root->error('aws', 'is required (used by ' . implode(', ', $users) . ')');
            }
            $c->aws = new Credentials('', '');
        } else {
            $aws->allowOnly(['key', 'secret']);
            $c->aws = new Credentials((string) $aws->secret('key'), (string) $aws->secret('secret'));
        }

        $cloudflare = $root->section('cloudflare', $c->dns->isCloudflare());
        if ($cloudflare !== null) {
            $cloudflare->allowOnly(['api_token']);
            $c->cloudflareToken = $cloudflare->secret('api_token');
        }

        $c->validateReferences($root, $scaling);

        if (!$errors->isEmpty()) {
            throw new ConfigException($errors->all());
        }
        return $c;
    }

    public static function resolvePath(string $path, string $baseDir): string
    {
        if ($path === '' || $path === ':memory:' || $path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
            return $path;
        }
        return rtrim($baseDir, '/') . '/' . $path;
    }

    /**
     * 需要 AWS 密钥的功能。
     *
     * @return string[]
     */
    private function awsUsers(): array
    {
        $users = [];
        if (!$this->dns->isCloudflare()) {
            $users[] = 'Route 53 DNS';
        }
        foreach ($this->providers as $name => $provider) {
            if ($provider->driver === ProviderConfig::DRIVER_EC2) {
                $users[] = "EC2 provider {$name}";
            }
        }
        if ($this->notify !== null) {
            $users[] = 'SES notifications';
        }
        return $users;
    }

    /**
     * 跨段的引用和唯一性检查。
     */
    private function validateReferences(Section $root, ScalingConfig $globalScaling): void
    {
        foreach ($globalScaling->metrics as $metric) {
            if ($metric->source !== '' && !isset($this->metricSources[$metric->source])) {
                $root->error("scaling.metrics.{$metric->name}.source", "unknown metric source \"{$metric->source}\"");
            }
        }

        $regions = [];
        foreach ($this->pools as $pool) {
            if ($pool->latencyRegion !== null) {
                if (isset($regions[$pool->latencyRegion])) {
                    $root->error("pools.{$pool->name}.latency_region", "\"{$pool->latencyRegion}\" is already used by pool \"{$regions[$pool->latencyRegion]}\"");
                }
                $regions[$pool->latencyRegion] = $pool->name;
            }

            if ($pool->scaling === $globalScaling) {
                continue;
            }
            foreach ($pool->scaling->metrics as $metric) {
                if ($metric->source !== '' && !isset($this->metricSources[$metric->source])) {
                    $root->error("pools.{$pool->name}.scaling.metrics.{$metric->name}.source", "unknown metric source \"{$metric->source}\"");
                }
            }
        }

        if ($this->drain->idleSource !== null && !isset($this->metricSources[$this->drain->idleSource])) {
            $root->error('drain.idle.source', "unknown metric source \"{$this->drain->idleSource}\"");
        }
    }
}
