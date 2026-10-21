<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Provider\ProviderInterface;

/**
 * 内存版云厂商。create 默认成功并返回 pending 实例，可用 failCreate / timeoutCreate 模拟失败。
 */
final class FakeProvider implements ProviderInterface
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

    /**
     * 让某个节点的实例进入 running 并分配地址。
     */
    public function boot(string $nodeId, string $ipv4, ?string $ipv6 = null): void
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
