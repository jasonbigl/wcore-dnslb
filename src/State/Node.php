<?php

namespace Wcore\Dnslb\State;

final class Node
{
    public const PROVISIONING = 'provisioning';
    public const BOOTING = 'booting';
    public const WARMING = 'warming';
    public const IN_SERVICE = 'in_service';
    public const REPLACING = 'replacing';
    public const DRAINING = 'draining';
    public const FAILED = 'failed';
    public const TERMINATING = 'terminating';
    public const TERMINATED = 'terminated';

    /** 还没被销毁的状态 */
    public const LIVE_STATES = [
        self::PROVISIONING, self::BOOTING, self::WARMING, self::IN_SERVICE,
        self::REPLACING, self::DRAINING, self::FAILED, self::TERMINATING,
    ];

    public const FAMILY_V4 = 'v4';
    public const FAMILY_V6 = 'v6';

    public string $nodeId;
    public string $pool;
    public string $state;
    /** providers 中的名称 */
    public string $provider;
    public string $providerRegion;
    public int $launchIndex;
    /** 当前建机方式（launchIndex）第一次发出建机请求的时间 */
    public ?int $launchStartedAt = null;
    public int $weight;
    public ?string $instanceId = null;
    public ?string $ipv4 = null;
    public ?string $ipv6 = null;
    public FamilyHealth $v4;
    public FamilyHealth $v6;
    public ?int $probedAt = null;
    /** 本节点是替补时，被替换的节点 ID */
    public ?string $replacementFor = null;
    /** 本节点被替换时，替补节点 ID */
    public ?string $replacedBy = null;
    /** 是否手动替换（手动替换和发布替换不会因旧节点恢复健康而取消） */
    public bool $replaceManual = false;
    /** 发布新版本时已存在的节点，等待被替换 */
    public bool $outdated = false;
    /** 排空中的节点确认已从 DNS 摘除的时间，排空时长从此时算起 */
    public ?int $dnsRemovedAt = null;
    public ?string $lastError = null;
    public int $createdAt;
    public int $updatedAt;
    public int $stateChangedAt;

    public function __construct(string $nodeId, string $pool, string $provider, string $providerRegion, int $launchIndex, int $weight, int $now)
    {
        $this->nodeId = $nodeId;
        $this->pool = $pool;
        $this->state = self::PROVISIONING;
        $this->provider = $provider;
        $this->providerRegion = $providerRegion;
        $this->launchIndex = $launchIndex;
        $this->weight = $weight;
        $this->v4 = new FamilyHealth();
        $this->v6 = new FamilyHealth();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->stateChangedAt = $now;
    }

    public function health(string $family): FamilyHealth
    {
        return $family === self::FAMILY_V6 ? $this->v6 : $this->v4;
    }

    public function ip(string $family): ?string
    {
        return $family === self::FAMILY_V6 ? $this->ipv6 : $this->ipv4;
    }

    public function transition(string $state, int $now): void
    {
        if ($state !== $this->state) {
            $this->state = $state;
            $this->stateChangedAt = $now;
        }
    }

    /**
     * 是否健康：IPv4 健康；启用 IPv6 且节点有 IPv6 地址时，IPv6 也健康。
     */
    public function isHealthy(bool $ipv6): bool
    {
        return $this->v4->healthy && (!$ipv6 || $this->ipv6 === null || $this->v6->healthy);
    }

    /**
     * 是否还在接流量：至少一个启用的协议族健康（全部不健康时已从 DNS 摘除）。
     */
    public function receivesTraffic(bool $ipv6): bool
    {
        return $this->v4->healthy || ($ipv6 && $this->ipv6 !== null && $this->v6->healthy);
    }

    /**
     * 处于当前状态的秒数。
     */
    public function stateAge(int $now): int
    {
        return $now - $this->stateChangedAt;
    }

    public static function fromRow(array $row): self
    {
        $node = new self(
            $row['node_id'],
            $row['pool'],
            $row['provider'],
            $row['provider_region'],
            (int) $row['launch_index'],
            (int) $row['weight'],
            (int) $row['created_at']
        );
        $node->state = $row['state'];
        $node->instanceId = $row['instance_id'];
        $node->ipv4 = $row['ipv4'];
        $node->ipv6 = $row['ipv6'];
        foreach ([self::FAMILY_V4 => $node->v4, self::FAMILY_V6 => $node->v6] as $family => $health) {
            $health->healthy = (bool) $row["{$family}_healthy"];
            $health->fails = (int) $row["{$family}_fails"];
            $health->passes = (int) $row["{$family}_passes"];
            $health->since = self::intOrNull($row["{$family}_since"]);
            $health->error = $row["{$family}_error"];
        }
        $node->probedAt = self::intOrNull($row['probed_at']);
        $node->replacementFor = $row['replacement_for'];
        $node->replacedBy = $row['replaced_by'];
        $node->replaceManual = (bool) $row['replace_manual'];
        $node->outdated = (bool) $row['outdated'];
        $node->dnsRemovedAt = self::intOrNull($row['dns_removed_at']);
        $node->launchStartedAt = self::intOrNull($row['launch_started_at']);
        $node->lastError = $row['last_error'];
        $node->updatedAt = (int) $row['updated_at'];
        $node->stateChangedAt = (int) $row['state_changed_at'];
        return $node;
    }

    public function toRow(): array
    {
        $row = [
            'node_id' => $this->nodeId,
            'pool' => $this->pool,
            'state' => $this->state,
            'provider' => $this->provider,
            'provider_region' => $this->providerRegion,
            'launch_index' => $this->launchIndex,
            'weight' => $this->weight,
            'instance_id' => $this->instanceId,
            'ipv4' => $this->ipv4,
            'ipv6' => $this->ipv6,
        ];
        foreach ([self::FAMILY_V4 => $this->v4, self::FAMILY_V6 => $this->v6] as $family => $health) {
            $row["{$family}_healthy"] = $health->healthy ? 1 : 0;
            $row["{$family}_fails"] = $health->fails;
            $row["{$family}_passes"] = $health->passes;
            $row["{$family}_since"] = $health->since;
            $row["{$family}_error"] = $health->error;
        }
        return $row + [
            'probed_at' => $this->probedAt,
            'replacement_for' => $this->replacementFor,
            'replaced_by' => $this->replacedBy,
            'replace_manual' => $this->replaceManual ? 1 : 0,
            'outdated' => $this->outdated ? 1 : 0,
            'dns_removed_at' => $this->dnsRemovedAt,
            'launch_started_at' => $this->launchStartedAt,
            'last_error' => $this->lastError,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'state_changed_at' => $this->stateChangedAt,
        ];
    }

    /**
     * @param mixed $value
     */
    private static function intOrNull($value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
