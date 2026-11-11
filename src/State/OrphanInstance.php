<?php

namespace Wcore\Dnslb\State;

/**
 * 云上带本服务标签、但没有任何节点引用的资源：实例，或弹性 IP（kind 为 address，此时 instanceId 是它的 allocation ID）。
 */
final class OrphanInstance
{
    public const KIND_INSTANCE = 'instance';
    public const KIND_ADDRESS = 'address';

    public string $provider;
    /** 实例 ID；kind 为 address 时是弹性 IP 的 allocation ID（eipalloc-...） */
    public string $instanceId;
    public string $region;
    /** 标签中的节点 ID */
    public ?string $nodeId;
    /** 第一次发现没有节点引用的时间 */
    public int $firstSeenAt;
    /** 已告警的时间 */
    public ?int $reportedAt = null;
    public string $kind;

    public function __construct(
        string $provider,
        string $instanceId,
        string $region,
        ?string $nodeId,
        int $firstSeenAt,
        string $kind = self::KIND_INSTANCE
    ) {
        $this->provider = $provider;
        $this->instanceId = $instanceId;
        $this->region = $region;
        $this->nodeId = $nodeId;
        $this->firstSeenAt = $firstSeenAt;
        $this->kind = $kind;
    }

    public function isAddress(): bool
    {
        return $this->kind === self::KIND_ADDRESS;
    }

    public static function fromRow(array $row): self
    {
        $orphan = new self(
            $row['provider'],
            $row['instance_id'],
            $row['region'],
            $row['node_id'],
            (int) $row['first_seen_at'],
            $row['kind'] ?? self::KIND_INSTANCE
        );
        $orphan->reportedAt = $row['reported_at'] === null ? null : (int) $row['reported_at'];
        return $orphan;
    }

    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'provider' => $this->provider,
            'region' => $this->region,
            'instance_id' => $this->instanceId,
            'node_id' => $this->nodeId,
            'first_seen_at' => $this->firstSeenAt,
        ];
    }
}
