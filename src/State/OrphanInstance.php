<?php

namespace Wcore\Dnslb\State;

/**
 * 云上带本服务标签、但没有节点引用的实例。
 */
final class OrphanInstance
{
    public string $provider;
    public string $instanceId;
    public string $region;
    /** 实例标签中的节点 ID */
    public ?string $nodeId;
    /** 第一次发现没有节点引用的时间 */
    public int $firstSeenAt;
    /** 已告警的时间 */
    public ?int $reportedAt = null;

    public function __construct(string $provider, string $instanceId, string $region, ?string $nodeId, int $firstSeenAt)
    {
        $this->provider = $provider;
        $this->instanceId = $instanceId;
        $this->region = $region;
        $this->nodeId = $nodeId;
        $this->firstSeenAt = $firstSeenAt;
    }

    public static function fromRow(array $row): self
    {
        $orphan = new self($row['provider'], $row['instance_id'], $row['region'], $row['node_id'], (int) $row['first_seen_at']);
        $orphan->reportedAt = $row['reported_at'] === null ? null : (int) $row['reported_at'];
        return $orphan;
    }

    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'region' => $this->region,
            'instance_id' => $this->instanceId,
            'node_id' => $this->nodeId,
            'first_seen_at' => $this->firstSeenAt,
        ];
    }
}
