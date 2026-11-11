<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\Provider\ElasticIpProvider;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\ProviderRegistry;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\OrphanInstance;
use Wcore\Dnslb\State\OrphanRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 孤儿实例巡检：云上带本服务标签、但没有任何节点引用的实例会一直计费且无人管理。来源包括断电丢失了状态库
 * 最近的提交、建机结果未知时在别处又建了一台、删除时实例暂时查不到、人工误操作等。
 *
 * 在所有建机区域（以及现有节点所在区域）列出实例，与状态库比对；连续 GRACE 秒没有节点引用才认定为孤儿，
 * 每台告警一次。不会自动删除（状态库从备份恢复时，正常节点也会被当成孤儿），由 Dnslb::cleanupOrphans() 确认后删除。
 *
 * 弹性 IP（launch 的 elastic_ip）用同样的办法巡检，见 trackAddresses()。
 */
final class OrphanTracker
{
    /** 连续这么久没有节点引用才认定为孤儿 */
    public const GRACE = 900;
    /** tick 中两次检查的最小间隔 */
    private const INTERVAL = 600;
    private const META_CHECKED_AT = 'orphans_checked_at';

    private Config $config;
    private ProviderRegistry $providers;
    private NodeRepository $nodes;
    private OrphanRepository $orphans;
    private Meta $meta;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        ProviderRegistry $providers,
        NodeRepository $nodes,
        OrphanRepository $orphans,
        Meta $meta,
        EventRecorder $events,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->providers = $providers;
        $this->nodes = $nodes;
        $this->orphans = $orphans;
        $this->meta = $meta;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * 比对云上实例和状态库，告警新确认的孤儿。距上次检查不到 INTERVAL 秒时跳过，$force 为 true 时总是检查。
     */
    public function track(bool $force = false): void
    {
        $now = $this->clock->now();
        $checkedAt = $this->meta->get(self::META_CHECKED_AT);
        if (!$force && $checkedAt !== null && $now - (int) $checkedAt < self::INTERVAL) {
            return;
        }
        $this->meta->set(self::META_CHECKED_AT, (string) $now);

        $nodes = $this->nodes->live();
        $referenced = [];
        $pending = [];
        foreach ($nodes as $node) {
            if ($node->instanceId !== null) {
                $referenced["{$node->provider}|{$node->instanceId}"] = true;
            } else {
                // 还没有实例 ID 的节点（建机结果未知、删除中）：同一节点 ID 的实例之后会被认领或删除
                $pending[$node->nodeId] = true;
            }
        }

        foreach ($this->regions($nodes) as $provider => $regions) {
            try {
                $instances = $this->providers->get($provider)->listManaged($this->config->service, array_keys($regions));
            } catch (\Throwable $e) {
                // 列不出来时保留已有记录，下次再查
                $this->logger->warning("Cannot list instances on {$provider} to look for orphans: {$e->getMessage()}");
                continue;
            }
            $suspects = [];
            foreach ($instances as $instance) {
                if ($instance->status === Instance::GONE
                    || isset($referenced["{$provider}|{$instance->id}"])
                    || ($instance->nodeId !== null && isset($pending[$instance->nodeId]))) {
                    continue;
                }
                $suspects[] = new OrphanInstance($provider, $instance->id, $instance->region, $instance->nodeId, $now);
            }
            $this->orphans->replaceForProvider($provider, $suspects);
        }

        $this->trackAddresses($nodes, $now);

        foreach ($this->confirmed() as $orphan) {
            if ($orphan->reportedAt !== null) {
                continue;
            }
            if ($orphan->isAddress()) {
                $this->events->warning(
                    'orphan_address',
                    sprintf(
                        '弹性 IP %s（%s/%s）带有本服务的标签，但已超过 %d 分钟没有节点引用，仍在计费并占用配额。确认是本服务遗留的再用 Dnslb::cleanupOrphans() 释放；'
                        . '如果同一云账号里有另一套部署使用了相同的服务名，这可能是它的地址，不要释放',
                        $orphan->instanceId,
                        $orphan->provider,
                        $orphan->region,
                        intdiv(self::GRACE, 60)
                    ),
                    null,
                    $orphan->nodeId,
                    $orphan->toArray()
                );
                $this->orphans->markReported($orphan, $now);
                continue;
            }
            $this->events->warning(
                'orphan_instance',
                sprintf(
                    '实例 %s（%s/%s）带有本服务的标签，但已超过 %d 分钟没有节点引用，仍在计费。确认是本服务遗留的再用 Dnslb::cleanupOrphans() 删除；'
                    . '如果同一云账号里有另一套部署使用了相同的服务名，这可能是它的机器，不要删除',
                    $orphan->instanceId,
                    $orphan->provider,
                    $orphan->region,
                    intdiv(self::GRACE, 60)
                ),
                null,
                $orphan->nodeId,
                $orphan->toArray()
            );
            $this->orphans->markReported($orphan, $now);
        }
    }

    /**
     * 弹性 IP：节点结束时会释放自己名下的地址，但换建机方式、进程中途退出等情况下可能留下没人管的。
     * 按节点标签比对：标签里的节点不是存活节点的就是可疑地址（存活节点名下多申请出来的，节点结束时会一并释放）。
     * 只查用到弹性 IP 的区域，没有开启 elastic_ip 的部署不会调用这个接口。
     *
     * @param Node[] $nodes 存活节点
     */
    private function trackAddresses(array $nodes, int $now): void
    {
        $live = [];
        foreach ($nodes as $node) {
            $live[$node->nodeId] = true;
        }
        foreach ($this->addressRegions($nodes) as $provider => $regions) {
            $impl = $this->providers->get($provider);
            if (!$impl instanceof ElasticIpProvider) {
                continue;
            }
            try {
                $addresses = $impl->listAddresses($this->config->service, array_keys($regions));
            } catch (\Throwable $e) {
                // 列不出来时保留已有记录，下次再查
                $this->logger->warning("Cannot list elastic IPs on {$provider} to look for orphans: {$e->getMessage()}");
                continue;
            }
            $suspects = [];
            foreach ($addresses as $address) {
                if ($address->nodeId !== null && isset($live[$address->nodeId])) {
                    continue;
                }
                $suspects[] = new OrphanInstance($provider, $address->id, $address->region, $address->nodeId, $now, OrphanInstance::KIND_ADDRESS);
            }
            $this->orphans->replaceForProvider($provider, $suspects, OrphanInstance::KIND_ADDRESS);
        }
    }

    /**
     * @param Node[] $nodes
     * @return array<string,array<string,true>> 云厂商 => 区域：launch 配置了 elastic_ip 的区域，以及现有弹性 IP 节点所在区域
     */
    private function addressRegions(array $nodes): array
    {
        $regions = [];
        foreach ($this->config->pools as $pool) {
            foreach ($pool->launch as $spec) {
                if ($spec->elasticIp()) {
                    $regions[$spec->provider][$spec->region] = true;
                }
            }
        }
        foreach ($nodes as $node) {
            if ($node->elasticIp && isset($this->config->providers[$node->provider])) {
                $regions[$node->provider][$node->providerRegion] = true;
            }
        }
        return $regions;
    }

    /**
     * @return OrphanInstance[] 已确认的孤儿（连续 GRACE 秒没有节点引用）
     */
    public function confirmed(): array
    {
        $now = $this->clock->now();
        return array_values(array_filter($this->orphans->all(), static function (OrphanInstance $orphan) use ($now): bool {
            return $now - $orphan->firstSeenAt >= self::GRACE;
        }));
    }

    public function forget(OrphanInstance $orphan): void
    {
        $this->orphans->delete($orphan);
    }

    /**
     * @param Node[] $nodes
     * @return array<string,array<string,true>> 云厂商 => 区域：所有池的建机区域，以及现有节点所在区域（例如已从配置删除的池）
     */
    private function regions(array $nodes): array
    {
        $regions = [];
        foreach ($this->config->pools as $pool) {
            foreach ($pool->launch as $spec) {
                $regions[$spec->provider][$spec->region] = true;
            }
        }
        foreach ($nodes as $node) {
            if (isset($this->config->providers[$node->provider])) {
                $regions[$node->provider][$node->providerRegion] = true;
            }
        }
        return $regions;
    }
}
