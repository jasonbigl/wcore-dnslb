<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Support\Logger;

/**
 * 演练模式：读操作照常执行，创建 / 删除只记录日志。
 */
final class DryRunProvider implements ProviderInterface
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
        $this->logger->info("[dry-run] would create instance {$nodeId} on {$spec->provider}/{$spec->region}");
        return new Instance($spec->provider, "dry-run-{$nodeId}", $spec->region, Instance::PENDING, null, null, $nodeId, $tags);
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
}
