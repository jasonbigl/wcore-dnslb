<?php

namespace Wcore\Dnslb\Config;

final class HealthCheckConfig
{
    private const DEFAULT_CANARIES = [
        'https://www.google.com/generate_204',
        'https://www.cloudflare.com/cdn-cgi/trace',
    ];

    public string $scheme;
    public int $port;
    public string $path;
    /** 用于 SNI 和 Host 头的域名 */
    public string $host;
    public bool $verifyTls;
    public int $expectStatusMin;
    public int $expectStatusMax;
    public ?string $expectBody;
    public float $timeout;
    public float $connectTimeout;
    public int $interval;
    public int $concurrency;
    public int $fall;
    public int $rise;
    /** @var string[] */
    public array $canaryUrls;
    public float $globalFreezeRatio;
    public int $poolFailoverAfter;

    /**
     * @param string|null $defaultHost 默认的 SNI / Host；dns.name 为通配符时为 null，此时 host 必填
     */
    public static function fromSection(Section $s, ?string $defaultHost): self
    {
        $s->allowOnly([
            'scheme', 'port', 'path', 'host', 'verify_tls', 'expect_status', 'expect_body',
            'timeout', 'connect_timeout', 'interval', 'concurrency', 'fall', 'rise',
            'canary_urls', 'global_freeze_ratio', 'pool_failover_after',
        ]);
        $c = new self();
        $c->scheme = $s->enum('scheme', ['https', 'http'], 'https');
        $c->port = $s->int('port', $c->scheme === 'https' ? 443 : 80, 1, 65535);
        $c->path = $s->string('path', '/health', '#^/\S*$#');
        if ($defaultHost === null && !$s->has('host')) {
            $s->error('host', 'is required when dns.name is a wildcard: set a concrete name covered by it, e.g. health.example.com (used for SNI and the Host header)');
        }
        $c->host = strtolower($s->string('host', $defaultHost ?? ''));
        if (strpos($c->host, '*') !== false) {
            $s->error('host', 'must be a concrete domain name, not a wildcard');
        }
        $c->verifyTls = $s->bool('verify_tls', true);

        $c->expectStatusMin = 200;
        $c->expectStatusMax = 299;
        $status = $s->raw('expect_status');
        if ($status !== null) {
            if (!is_array($status) || count($status) !== 2 || !is_int($status[0] ?? null) || !is_int($status[1] ?? null)
                || $status[0] < 100 || $status[1] > 599 || $status[0] > $status[1]) {
                $s->error('expect_status', 'must be [min, max] status codes, e.g. [200, 299]');
            } else {
                [$c->expectStatusMin, $c->expectStatusMax] = $status;
            }
        }

        $c->expectBody = $s->optionalString('expect_body');
        $c->timeout = $s->float('timeout', 5, 0.5, 30);
        $c->connectTimeout = $s->float('connect_timeout', min(3.0, $c->timeout), 0.1, 30);
        $c->interval = $s->int('interval', 10, 2, 55);
        $c->concurrency = $s->int('concurrency', 50, 1, 1000);
        $c->fall = $s->int('fall', 3, 1, 100);
        $c->rise = $s->int('rise', 2, 1, 100);
        $c->canaryUrls = $s->stringList('canary_urls', self::DEFAULT_CANARIES);
        foreach ($c->canaryUrls as $i => $url) {
            if (!preg_match('#^https?://#', $url)) {
                $s->error("canary_urls.{$i}", 'must be an http(s) URL');
            }
        }
        $c->globalFreezeRatio = $s->float('global_freeze_ratio', 0.5, 0.01, 1.0);
        $c->poolFailoverAfter = $s->int('pool_failover_after', 180, 0);
        return $c;
    }
}
