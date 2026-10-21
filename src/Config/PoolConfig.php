<?php

namespace Wcore\Dnslb\Config;

final class PoolConfig
{
    public string $name;
    /** Route 53 latency 记录使用的 AWS 区域；使用 Cloudflare 时为 null */
    public ?string $latencyRegion = null;
    public int $min;
    public int $max;
    /** @var LaunchSpec[] 按优先级排列 */
    public array $launch = [];
    public ScalingConfig $scaling;

    public static function fromSection(
        string $name,
        Section $s,
        array $providers,
        ScalingConfig $globalScaling,
        array $globalScalingData,
        string $dnsProvider = DnsConfig::PROVIDER_ROUTE53
    ): self {
        $s->allowOnly(['latency_region', 'min', 'max', 'launch', 'scaling']);
        $c = new self();
        $c->name = $name;
        if (!preg_match(Config::NAME_PATTERN, $name)) {
            $s->error(null, 'pool name must be lowercase letters, digits and single dashes, at most 20 characters');
        }
        if ($dnsProvider === DnsConfig::PROVIDER_ROUTE53) {
            $c->latencyRegion = $s->string('latency_region', Section::REQUIRED, '/^[a-z]{2}(-gov)?-[a-z]+-\d+$/');
        } elseif ($s->has('latency_region')) {
            $s->error('latency_region', "is not used with dns.provider {$dnsProvider} (traffic is spread across all pools)");
        }
        $c->min = $s->int('min', 1, 0, 1000);
        $c->max = $s->int('max', Section::REQUIRED, 1, 1000);
        if ($c->max < $c->min) {
            $s->error('max', 'must be greater than or equal to min');
        }
        foreach ($s->sectionList('launch') as $index => $launch) {
            $c->launch[] = LaunchSpec::fromSection($index, $launch, $providers);
        }

        // 池级 scaling 覆盖全局配置（深度合并；把某个指标设为 null 可在本池禁用它）
        $override = $s->section('scaling');
        if ($override === null) {
            $c->scaling = $globalScaling;
        } else {
            $merged = array_replace_recursive($globalScalingData, $override->toArray());
            $c->scaling = ScalingConfig::fromSection($override->withData($merged));
        }
        return $c;
    }
}
