<?php

namespace Wcore\Dnslb\Health;

/**
 * 一轮探测的结果：各节点的结果，以及探测机自身在各协议族上的网络是否正常（金丝雀）。
 */
final class ProbeRound
{
    /** @var array<string,ProbeResult> 以 ProbeTarget::key() 为键 */
    public array $results;
    /** @var array<string,bool> 协议族 => 至少一个金丝雀地址可达 */
    public array $canaryOk;

    /**
     * @param array<string,ProbeResult> $results
     * @param array<string,bool> $canaryOk
     */
    public function __construct(array $results, array $canaryOk)
    {
        $this->results = $results;
        $this->canaryOk = $canaryOk;
    }
}
