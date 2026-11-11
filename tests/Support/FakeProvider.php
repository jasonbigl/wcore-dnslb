<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Provider\Address;
use Wcore\Dnslb\Provider\ConsoleOutputProvider;
use Wcore\Dnslb\Provider\ElasticIpProvider;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Provider\ProviderInterface;

/**
 * 内存版云厂商。create 默认成功并返回 pending 实例，可用 failCreate / timeoutCreate 模拟失败。
 *
 * launch 配置了 elastic_ip 时 create 会申请一个弹性 IP（$addresses），但不关联：实例 running 后由 attachAddress 关联，
 * 设置 attachOnCreate 可以模拟建机时就关联好。实例删除后弹性 IP 仍然保留并关联着，直到被释放。
 */
final class FakeProvider implements ProviderInterface, ConsoleOutputProvider, ElasticIpProvider
{
    public string $name;
    /** @var array<string,Instance> */
    public array $instances = [];
    /** @var string[] 调用记录，例如 "create web-tokyo-abc123 ap-northeast-1"、"terminate i-1" */
    public array $calls = [];
    /** @var string[] 收到的 user-data */
    public array $userData = [];
    /** 非 null 时 create 抛出 ProviderException */
    public ?string $failCreate = null;
    /** 非 null 时 create 抛出 OutcomeUnknownException（实例实际没有建出） */
    public ?string $unknownCreate = null;
    /** true 时 create 实际创建实例，但抛出网络错误（模拟请求超时） */
    public bool $timeoutCreate = false;
    /** true 时 create 实际创建实例，但随后抛出异常（模拟进程在保存结果前退出） */
    public bool $crashAfterCreate = false;
    public bool $listFails = false;
    /** consoleOutput() 的返回值（实例 ID => 串口输出）；consoleFails 非 null 时抛出 ProviderException */
    public array $consoleOutputs = [];
    public ?string $consoleFails = null;
    /** @var array<string,Address> 弹性 IP（地址 ID => 地址） */
    public array $addresses = [];
    /** true 时 create 返回已经 running 并关联好弹性 IP 的实例 */
    public bool $attachOnCreate = false;
    /** 非 null 时 attachAddress / releaseAddresses / releaseAddress 抛出 ProviderException */
    public ?string $attachFails = null;
    public ?string $releaseFails = null;
    public bool $listAddressesFails = false;
    private int $sequence = 0;

    public function __construct(string $name = 'aws')
    {
        $this->name = $name;
    }

    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance
    {
        $this->calls[] = "create {$nodeId} {$spec->region}";
        if ($this->failCreate !== null) {
            throw new ProviderException($this->failCreate, 'Fake');
        }
        if ($this->unknownCreate !== null) {
            throw new OutcomeUnknownException($this->unknownCreate);
        }
        $instance = new Instance($this->name, 'i-' . ++$this->sequence, $spec->region, Instance::PENDING, null, null, $nodeId, $tags + [self::TAG_NODE => $nodeId]);
        if ($spec->elasticIp()) {
            $this->calls[] = "allocate {$nodeId}";
            $address = $this->addAddress("eipalloc-{$this->sequence}", "198.51.100.{$this->sequence}", $nodeId, null, $spec->region);
            $instance->elasticIp = true;
            if ($this->attachOnCreate) {
                $address->instanceId = $instance->id;
                $instance->status = Instance::RUNNING;
                $instance->ipv4 = $address->ip;
            }
        }
        $this->instances[$instance->id] = $instance;
        $this->userData[$nodeId] = $userData;
        if ($this->timeoutCreate) {
            throw new HttpException('POST create: Operation timed out');
        }
        if ($this->crashAfterCreate) {
            throw new \RuntimeException('process exited before saving the result');
        }
        return clone $instance;
    }

    public function get(string $region, string $instanceId): ?Instance
    {
        return isset($this->instances[$instanceId]) ? clone $this->instances[$instanceId] : null;
    }

    public function findByNodeId(string $region, string $nodeId): ?Instance
    {
        $this->calls[] = "find {$nodeId}";
        foreach ($this->instances as $instance) {
            if ($instance->nodeId === $nodeId && $instance->status !== Instance::GONE) {
                return clone $instance;
            }
        }
        return null;
    }

    public function listManaged(string $service, array $regions): array
    {
        if ($this->listFails) {
            throw new ProviderException('API unavailable', 'Fake');
        }
        return array_values(array_map(static function (Instance $i): Instance {
            return clone $i;
        }, array_filter($this->instances, static function (Instance $i): bool {
            return $i->status !== Instance::GONE;
        })));
    }

    public function terminate(string $region, string $instanceId): void
    {
        $this->calls[] = "terminate {$instanceId}";
        if (isset($this->instances[$instanceId])) {
            $this->instances[$instanceId]->status = Instance::GONE;
        }
    }

    public function consoleOutput(string $region, string $instanceId): ?string
    {
        $this->calls[] = "console {$instanceId}";
        if ($this->consoleFails !== null) {
            throw new ProviderException($this->consoleFails, 'UnauthorizedOperation');
        }
        return $this->consoleOutputs[$instanceId] ?? null;
    }

    public function attachAddress(string $region, string $nodeId, string $instanceId): ?string
    {
        $this->calls[] = "attach {$nodeId}";
        if ($this->attachFails !== null) {
            throw new ProviderException($this->attachFails, 'Fake');
        }
        foreach ($this->addresses as $address) {
            if ($address->nodeId === $nodeId) {
                $address->instanceId = $instanceId;
                if (isset($this->instances[$instanceId])) {
                    $this->instances[$instanceId]->ipv4 = $address->ip;
                }
                return $address->ip;
            }
        }
        return null;
    }

    public function releaseAddresses(string $region, string $nodeId): void
    {
        $this->calls[] = "release {$nodeId}";
        if ($this->releaseFails !== null) {
            throw new ProviderException($this->releaseFails, 'Fake');
        }
        foreach ($this->addresses as $id => $address) {
            if ($address->nodeId === $nodeId) {
                unset($this->addresses[$id]);
            }
        }
    }

    public function listAddresses(string $service, array $regions): array
    {
        $this->calls[] = 'list-addresses ' . implode(',', $regions);
        if ($this->listAddressesFails) {
            throw new ProviderException('API unavailable', 'Fake');
        }
        return array_values(array_map(static function (Address $address): Address {
            return clone $address;
        }, $this->addresses));
    }

    public function releaseAddress(string $region, string $addressId, bool $detach): void
    {
        $this->calls[] = "release-address {$addressId}" . ($detach ? ' detach' : '');
        if ($this->releaseFails !== null) {
            throw new ProviderException($this->releaseFails, 'Fake');
        }
        $address = $this->addresses[$addressId] ?? null;
        if ($address !== null && $address->instanceId !== null && !$detach) {
            throw new ProviderException("{$addressId} is still associated with {$address->instanceId}", 'AddressInUse');
        }
        unset($this->addresses[$addressId]);
    }

    /**
     * 直接放入一个弹性 IP（跳过建机流程）。
     */
    public function addAddress(string $id, string $ip, ?string $nodeId, ?string $instanceId = null, string $region = 'ap-northeast-1'): Address
    {
        return $this->addresses[$id] = new Address($this->name, $id, $region, $ip, $instanceId, $nodeId);
    }

    /**
     * 让某个节点的实例进入 running 并分配地址（使用弹性 IP 的实例传 null：关联之前没有公网 IPv4）。
     */
    public function boot(string $nodeId, ?string $ipv4, ?string $ipv6 = null): void
    {
        foreach ($this->instances as $instance) {
            if ($instance->nodeId === $nodeId) {
                $instance->status = Instance::RUNNING;
                $instance->ipv4 = $ipv4;
                $instance->ipv6 = $ipv6;
                return;
            }
        }
        throw new \LogicException("No instance for {$nodeId}");
    }

    public function instanceOf(string $nodeId): ?Instance
    {
        foreach ($this->instances as $instance) {
            if ($instance->nodeId === $nodeId) {
                return $instance;
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    public function callsMatching(string $prefix): array
    {
        return array_values(array_filter($this->calls, static function (string $call) use ($prefix): bool {
            return strpos($call, $prefix) === 0;
        }));
    }
}
