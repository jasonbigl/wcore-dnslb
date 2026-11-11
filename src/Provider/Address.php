<?php

namespace Wcore\Dnslb\Provider;

/**
 * 单独申请、需要单独释放的公网 IPv4 地址（EC2 的弹性 IP）。
 */
final class Address
{
    /** providers 中的名称 */
    public string $provider;
    /** 云厂商的地址 ID（EC2 为 allocation ID，eipalloc-...） */
    public string $id;
    public string $region;
    public string $ip;
    /** 当前关联的实例 ID，未关联时为 null */
    public ?string $instanceId;
    /** 标签中的节点 ID */
    public ?string $nodeId;
    /** @var array<string,string> */
    public array $tags;

    /**
     * @param array<string,string> $tags
     */
    public function __construct(string $provider, string $id, string $region, string $ip, ?string $instanceId, ?string $nodeId, array $tags = [])
    {
        $this->provider = $provider;
        $this->id = $id;
        $this->region = $region;
        $this->ip = $ip;
        $this->instanceId = $instanceId;
        $this->nodeId = $nodeId;
        $this->tags = $tags;
    }
}
