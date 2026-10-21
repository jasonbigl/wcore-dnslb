<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\HttpException;

/**
 * 云厂商接口。
 *
 * 错误约定：
 * - ProviderException：云厂商明确拒绝（容量不足、配额、参数错误等），请求肯定没有生效；
 * - HttpException：网络错误，请求可能已经生效，调用方应稍后按节点 ID 查找确认；
 * - OutcomeUnknownException（只用于 create）：重试用尽后仍是服务端错误或限流，与网络错误一样处理。
 */
interface ProviderInterface
{
    /** 节点 ID 标签（EC2）/ 实例名（Linode）之外的统一标签键 */
    public const TAG_SERVICE = 'dnslb:service';
    public const TAG_POOL = 'dnslb:pool';
    public const TAG_NODE = 'dnslb:node';

    /**
     * 创建并启动实例。同一个 $nodeId 重复调用不会创建第二台。
     *
     * @param array<string,string> $tags
     * @throws ProviderException|HttpException|OutcomeUnknownException
     */
    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance;

    /**
     * @return Instance|null 实例不存在时返回 null
     * @throws ProviderException|HttpException
     */
    public function get(string $region, string $instanceId): ?Instance;

    /**
     * 按节点 ID 查找实例（用于创建请求结果不确定时）。
     *
     * @throws ProviderException|HttpException
     */
    public function findByNodeId(string $region, string $nodeId): ?Instance;

    /**
     * 列出属于该服务的所有实例（不含已删除的）。
     *
     * @param string[] $regions 需要查询的区域（Linode 一次即可列出全部区域）
     * @return Instance[]
     * @throws ProviderException|HttpException
     */
    public function listManaged(string $service, array $regions): array;

    /**
     * 删除实例。实例已不存在时视为成功。
     *
     * @throws ProviderException|HttpException
     */
    public function terminate(string $region, string $instanceId): void;
}
