<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Aws\Ec2\Ec2Client;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\ProviderConfig;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Provider\Linode\LinodeClient;
use Wcore\Dnslb\Provider\Linode\LinodeProvider;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 按配置创建各云厂商实例（按需创建并复用）。
 */
final class ProviderRegistry
{
    private Config $config;
    private Transport $transport;
    private Clock $clock;
    private ?Logger $dryRunLogger;
    /** @var array<string,ProviderInterface> 实际实现 */
    private array $providers = [];
    /** @var array<string,ProviderInterface> 对外使用的实例（演练模式下包装成 DryRunProvider） */
    private array $wrapped = [];
    private ?Ec2Client $ec2 = null;
    /** @var array<string,LinodeClient> */
    private array $linodeClients = [];

    /**
     * @param Logger|null $dryRunLogger 不为 null 时进入演练模式：写操作只记录日志，不实际执行
     */
    public function __construct(Config $config, Transport $transport, Clock $clock, ?Logger $dryRunLogger = null)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->clock = $clock;
        $this->dryRunLogger = $dryRunLogger;
    }

    public function get(string $name): ProviderInterface
    {
        if (!isset($this->wrapped[$name])) {
            $provider = $this->providers[$name] ??= $this->create($name);
            $this->wrapped[$name] = $this->dryRunLogger === null ? $provider : new DryRunProvider($provider, $this->dryRunLogger);
        }
        return $this->wrapped[$name];
    }

    /**
     * 替换某个云厂商的实现（测试用）。演练模式下同样会被包装。
     */
    public function set(string $name, ProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
        unset($this->wrapped[$name]);
    }

    public function ec2Client(): Ec2Client
    {
        return $this->ec2 ??= new Ec2Client($this->transport, $this->config->aws, $this->clock);
    }

    public function linodeClient(string $name): LinodeClient
    {
        $config = $this->config->providers[$name] ?? null;
        if ($config === null || $config->driver !== ProviderConfig::DRIVER_LINODE) {
            throw new \InvalidArgumentException("Not a Linode provider: {$name}");
        }
        return $this->linodeClients[$name] ??= new LinodeClient($this->transport, (string) $config->token, (string) $config->apiUrl, $this->clock);
    }

    /**
     * 每个云厂商在各池 launch 配置中用到的区域。
     *
     * @return array<string,string[]>
     */
    public function regionsByProvider(): array
    {
        $result = [];
        foreach ($this->config->pools as $pool) {
            foreach ($pool->launch as $spec) {
                $result[$spec->provider][$spec->region] = true;
            }
        }
        return array_map('array_keys', $result);
    }

    private function create(string $name): ProviderInterface
    {
        $config = $this->config->providers[$name] ?? null;
        if ($config === null) {
            throw new \InvalidArgumentException("Unknown provider: {$name}");
        }
        if ($config->driver === ProviderConfig::DRIVER_LINODE) {
            return new LinodeProvider($name, $this->linodeClient($name));
        }
        return new Ec2Provider($name, $this->ec2Client(), $this->config->dns->ipv6);
    }
}
