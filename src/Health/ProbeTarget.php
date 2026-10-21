<?php

namespace Wcore\Dnslb\Health;

/**
 * 一次探测的对象：某个节点的某个协议族地址。
 */
final class ProbeTarget
{
    public string $nodeId;
    /** Node::FAMILY_V4 或 Node::FAMILY_V6 */
    public string $family;
    public string $ip;

    public function __construct(string $nodeId, string $family, string $ip)
    {
        $this->nodeId = $nodeId;
        $this->family = $family;
        $this->ip = $ip;
    }

    public function key(): string
    {
        return "{$this->nodeId}/{$this->family}";
    }
}
