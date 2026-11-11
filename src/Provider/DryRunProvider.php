<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Support\Logger;

/**
 * 演练模式：读操作照常执行，创建 / 删除只记录日志（弹性 IP 的关联和释放也一样）。
 */
final class DryRunProvider implements ProviderInterface, ElasticIpProvider
{
    private ProviderInterface $inner;
    private Logger $logger;

    public function __construct(ProviderInterface $inner, Logger $logger)
    {
        $this->inner = $inner;
        $this->logger = $logger;
    }

    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance
    {
        $this->logger->info("[dry-run] would create instance {$nodeId} on {$spec->provider}/{$spec->region}" . ($spec->elasticIp() ? ' with an elastic IP' : ''));
        $instance = new Instance($spec->provider, "dry-run-{$nodeId}", $spec->region, Instance::PENDING, null, null, $nodeId, $tags);
        $instance->elasticIp = $spec->elasticIp();
        return $instance;
    }

    public function get(string $region, string $instanceId): ?Instance
    {
        return $this->inner->get($region, $instanceId);
    }

    public function findByNodeId(string $region, string $nodeId): ?Instance
    {
        return $this->inner->findByNodeId($region, $nodeId);
    }

    public function listManaged(string $service, array $regions): array
    {
        return $this->inner->listManaged($service, $regions);
    }

    public function terminate(string $region, string $instanceId): void
    {
        $this->logger->info("[dry-run] would terminate instance {$instanceId} in {$region}");
    }

    public function attachAddress(string $region, string $nodeId, string $instanceId): ?string
    {
        $this->logger->info("[dry-run] would associate the elastic IP of {$nodeId} with instance {$instanceId} in {$region}");
        return null;
    }

    public function releaseAddresses(string $region, string $nodeId): void
    {
        $this->logger->info("[dry-run] would release the elastic IP of {$nodeId} in {$region}");
    }

    public function listAddresses(string $service, array $regions): array
    {
        return $this->inner instanceof ElasticIpProvider ? $this->inner->listAddresses($service, $regions) : [];
    }

    public function releaseAddress(string $region, string $addressId, bool $detach): void
    {
        $this->logger->info("[dry-run] would release elastic IP {$addressId} in {$region}");
    }
}
