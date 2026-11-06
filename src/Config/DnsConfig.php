<?php

namespace Wcore\Dnslb\Config;

final class DnsConfig
{
    public const PROVIDER_ROUTE53 = 'route53';
    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const ROUTING_MULTIVALUE = 'multivalue';
    public const ROUTING_WEIGHTED = 'weighted';

    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9_]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';
    private const ZONE_KEYS = ['zone_id', 'name', 'pool_domain'];
    private const ROUTE53_ONLY = ['pool_domain', 'pool_routing'];
    private const CLOUDFLARE_ONLY = ['proxied'];

    /** route53：按延迟就近选择池；cloudflare：所有池的节点轮询 */
    public string $provider;
    /**
     * 发布区域，对外名称 => 区域，按配置顺序。所有区域发布同一批节点，各自独立同步。
     *
     * @var array<string,DnsZone>
     */
    public array $zones = [];
    /** 记录的 TTL（Cloudflare 代理记录固定为自动） */
    public int $ttl;
    public bool $ipv6;
    /** Route 53：池内的路由方式 */
    public string $poolRouting = self::ROUTING_MULTIVALUE;
    /** Cloudflare：是否开启代理（橙云） */
    public bool $proxied = false;

    /**
     * 只有一个区域时直接写 zone_id、name（Route 53 还可以写 pool_domain）；
     * 多个区域时写 zones 列表，每项各自的 zone_id、name（、pool_domain）。
     */
    public static function fromSection(Section $s): self
    {
        $s->allowOnly(array_merge(['provider', 'zone_id', 'name', 'zones', 'ttl', 'ipv6'], self::ROUTE53_ONLY, self::CLOUDFLARE_ONLY));
        $c = new self();
        $c->provider = $s->enum('provider', [self::PROVIDER_ROUTE53, self::PROVIDER_CLOUDFLARE], self::PROVIDER_ROUTE53);
        $c->ipv6 = $s->bool('ipv6', false);

        foreach ($c->isCloudflare() ? self::ROUTE53_ONLY : self::CLOUDFLARE_ONLY as $key) {
            if ($s->has($key)) {
                $s->error($key, "is not used with provider {$c->provider}");
            }
        }

        if ($s->has('zones')) {
            foreach (self::ZONE_KEYS as $key) {
                if ($s->has($key)) {
                    $s->error($key, 'cannot be combined with zones (set it on each zone)');
                }
            }
            $poolDomains = [];
            foreach ($s->sectionList('zones') as $section) {
                $section->allowOnly(self::ZONE_KEYS);
                if ($c->isCloudflare() && $section->has('pool_domain')) {
                    $section->error('pool_domain', "is not used with provider {$c->provider}");
                }
                $zone = $c->parseZone($section);
                if ($zone->name === '') {
                    continue;
                }
                if (isset($c->zones[$zone->name])) {
                    $section->error('name', "\"{$zone->name}\" is already used by another zone");
                    continue;
                }
                if ($zone->poolDomain !== '') {
                    if (isset($poolDomains[$zone->poolDomain])) {
                        $section->error('pool_domain', "\"{$zone->poolDomain}\" is already used by zone \"{$poolDomains[$zone->poolDomain]}\"");
                    }
                    $poolDomains[$zone->poolDomain] = $zone->name;
                }
                $c->zones[$zone->name] = $zone;
            }
        } else {
            $zone = $c->parseZone($s);
            $c->zones[$zone->name] = $zone;
        }

        if ($c->isCloudflare()) {
            $c->proxied = $s->bool('proxied', true);
            $c->ttl = $s->int('ttl', 60, 30, 86400);
            return $c;
        }
        $c->ttl = $s->int('ttl', 60, 1, 172800);
        $c->poolRouting = $s->enum('pool_routing', [self::ROUTING_MULTIVALUE, self::ROUTING_WEIGHTED], self::ROUTING_MULTIVALUE);
        return $c;
    }

    public function isCloudflare(): bool
    {
        return $this->provider === self::PROVIDER_CLOUDFLARE;
    }

    /**
     * @return string[] 各区域的对外名称，按配置顺序
     */
    public function names(): array
    {
        return array_keys($this->zones);
    }

    /**
     * 各区域的对外名称，用于消息，例如 "app.example.com、app.example.net"。
     */
    public function nameList(): string
    {
        return implode('、', $this->names());
    }

    /**
     * 第一个区域（默认的健康检查域名取它的 name）；配置有误、没有区域时为 null。
     */
    public function firstZone(): ?DnsZone
    {
        foreach ($this->zones as $zone) {
            return $zone;
        }
        return null;
    }

    private function parseZone(Section $s): DnsZone
    {
        $zone = new DnsZone();
        $zone->name = strtolower(rtrim($s->string('name'), '.'));
        $zone->wildcard = strpos($zone->name, '*.') === 0;
        $base = $zone->wildcard ? substr($zone->name, 2) : $zone->name;
        if ($zone->name !== '' && !preg_match(self::HOSTNAME, $base)) {
            $s->error('name', "invalid domain name \"{$zone->name}\" (a wildcard is only allowed as the leftmost label, e.g. *.example.com)");
        }

        if ($this->isCloudflare()) {
            $zone->zoneId = $s->string('zone_id', Section::REQUIRED, '/^[0-9a-f]{32}$/');
            return $zone;
        }

        $zoneId = $s->string('zone_id');
        $zone->zoneId = substr((string) strrchr('/' . $zoneId, '/'), 1);
        // 通配符域名下，池记录放在不太可能与真实子域名冲突的 dnslb-pool 下
        $defaultPoolDomain = $zone->wildcard ? '{pool}.dnslb-pool.' . $base : '{pool}.pool.' . $zone->name;
        $zone->poolDomain = strtolower(rtrim($s->string('pool_domain', $defaultPoolDomain), '.'));
        if (strpos($zone->poolDomain, '{pool}.') !== 0 || substr_count($zone->poolDomain, '{') !== 1) {
            $s->error('pool_domain', 'must start with "{pool}." and contain no other placeholders');
        } elseif (!preg_match(self::HOSTNAME, str_replace('{pool}', 'x', $zone->poolDomain))) {
            $s->error('pool_domain', "invalid domain name \"{$zone->poolDomain}\"");
        }
        return $zone;
    }
}
