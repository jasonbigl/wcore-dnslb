<?php

namespace Wcore\Dnslb\Provider;

/**
 * 云厂商实例的统一表示。
 */
final class Instance
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    /** 已停止（未删除），视为不可用 */
    public const STOPPED = 'stopped';
    /** 已删除或正在删除 */
    public const GONE = 'gone';

    /** providers 中的名称 */
    public string $provider;
    public string $id;
    public string $region;
    public string $status;
    public ?string $ipv4;
    public ?string $ipv6;
    /** 从实例名 / 标签中识别出的节点 ID */
    public ?string $nodeId;
    /** @var array<string,string> */
    public array $tags;
    public ?int $createdAt;

    /**
     * @param array<string,string> $tags
     */
    public function __construct(
        string $provider,
        string $id,
        string $region,
        string $status,
        ?string $ipv4,
        ?string $ipv6,
        ?string $nodeId,
        array $tags = [],
        ?int $createdAt = null
    ) {
        $this->provider = $provider;
        $this->id = $id;
        $this->region = $region;
        $this->status = $status;
        $this->ipv4 = $ipv4;
        $this->ipv6 = $ipv6;
        $this->nodeId = $nodeId;
        $this->tags = $tags;
        $this->createdAt = $createdAt;
    }
}
