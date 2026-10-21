<?php

namespace Wcore\Dnslb\Config;

final class DnsConfig
{
    public const PROVIDER_ROUTE53 = 'route53';
    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const ROUTING_MULTIVALUE = 'multivalue';
    public const ROUTING_WEIGHTED = 'weighted';

    private const HOSTNAME = '/^(?=.{1,253}$)([a-z0-9_]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{0,61}[a-z0-9]$/';
    private const ROUTE53_ONLY = ['pool_domain', 'pool_routing'];
    private const CLOUDFLARE_ONLY = ['proxied'];

    /** route53：按延迟就近选择池；cloudflare：所有池的节点轮询 */
    public string $provider;
    public string $zoneId;
    /** 对外服务的域名，不带结尾点号；可以是通配符，如 *.example.com */
    public string $name;
    /** name 是否为通配符域名 */
    public bool $wildcard;
    /** Route 53：第二层记录名模板，例如 "{pool}.pool.app.example.com" */
    public string $poolDomain = '';
    /** 记录的 TTL（Cloudflare 代理记录固定为自动） */
    public int $ttl;
    public bool $ipv6;
    /** Route 53：池内的路由方式 */
    public string $poolRouting = self::ROUTING_MULTIVALUE;
    /** Cloudflare：是否开启代理（橙云） */
    public bool $proxied = false;

    public static function fromSection(Section $s): self
    {
        $s->allowOnly(array_merge(['provider', 'zone_id', 'name', 'ttl', 'ipv6'], self::ROUTE53_ONLY, self::CLOUDFLARE_ONLY));
        $c = new self();
        $c->provider = $s->enum('provider', [self::PROVIDER_ROUTE53, self::PROVIDER_CLOUDFLARE], self::PROVIDER_ROUTE53);
        $c->name = strtolower(rtrim($s->string('name'), '.'));
        $c->wildcard = strpos($c->name, '*.') === 0;
        $base = $c->wildcard ? substr($c->name, 2) : $c->name;
        if ($c->name !== '' && !preg_match(self::HOSTNAME, $base)) {
            $s->error('name', "invalid domain name \"{$c->name}\" (a wildcard is only allowed as the leftmost label, e.g. *.example.com)");
        }
        $c->ipv6 = $s->bool('ipv6', false);

        foreach ($c->provider === self::PROVIDER_CLOUDFLARE ? self::ROUTE53_ONLY : self::CLOUDFLARE_ONLY as $key) {
            if ($s->has($key)) {
                $s->error($key, "is not used with provider {$c->provider}");
            }
        }

        if ($c->provider === self::PROVIDER_CLOUDFLARE) {
            $c->zoneId = $s->string('zone_id', Section::REQUIRED, '/^[0-9a-f]{32}$/');
            $c->proxied = $s->bool('proxied', true);
            $c->ttl = $s->int('ttl', 60, 30, 86400);
            return $c;
        }

        $zoneId = $s->string('zone_id');
        $c->zoneId = substr((string) strrchr('/' . $zoneId, '/'), 1);
        // 通配符域名下，池记录放在不太可能与真实子域名冲突的 dnslb-pool 下
        $defaultPoolDomain = $c->wildcard ? '{pool}.dnslb-pool.' . $base : '{pool}.pool.' . $c->name;
        $c->poolDomain = strtolower(rtrim($s->string('pool_domain', $defaultPoolDomain), '.'));
        if (strpos($c->poolDomain, '{pool}.') !== 0 || substr_count($c->poolDomain, '{') !== 1) {
            $s->error('pool_domain', 'must start with "{pool}." and contain no other placeholders');
        } elseif (!preg_match(self::HOSTNAME, str_replace('{pool}', 'x', $c->poolDomain))) {
            $s->error('pool_domain', "invalid domain name \"{$c->poolDomain}\"");
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
     * Route 53：某个池的第二层记录名。
     */
    public function poolRecordName(string $pool): string
    {
        return str_replace('{pool}', $pool, $this->poolDomain);
    }
}
