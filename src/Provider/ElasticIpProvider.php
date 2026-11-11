<?php

namespace Wcore\Dnslb\Provider;

/**
 * 可选能力：为实例单独申请公网 IPv4（弹性 IP）。目前只有 EC2 支持，由 launch 配置的 elastic_ip 开启。
 *
 * 弹性 IP 在 create() 中申请并关联；实例删除后它不会自动释放，仍然计费并占用配额，所以节点结束时必须调用
 * releaseAddresses()。地址按节点 ID 标签归属到节点，这里的方法都可以重复调用。
 *
 * 错误约定与 ProviderInterface 相同。
 */
interface ElasticIpProvider
{
    /**
     * 把节点的弹性 IP 关联到它的实例（实例必须已经 running）。create() 中没来得及关联时由 tick 调用。
     *
     * @return string|null 已关联的公网 IPv4；节点名下没有弹性 IP 时返回 null
     */
    public function attachAddress(string $region, string $nodeId, string $instanceId): ?string;

    /**
     * 释放节点名下的所有弹性 IP，仍关联着实例时先解除关联。没有时视为成功。
     */
    public function releaseAddresses(string $region, string $nodeId): void;

    /**
     * 列出带本服务标签的弹性 IP（孤儿巡检用）。
     *
     * @param string[] $regions
     * @return Address[]
     */
    public function listAddresses(string $service, array $regions): array;

    /**
     * 释放一个弹性 IP（清理孤儿用）。已经不存在时视为成功。
     *
     * @param bool $detach 仍关联着实例时是否先解除关联；为 false 时抛出 ProviderException，不做任何修改
     */
    public function releaseAddress(string $region, string $addressId, bool $detach): void;
}
