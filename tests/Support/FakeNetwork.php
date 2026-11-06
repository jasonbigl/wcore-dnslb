<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * 模拟外部网络：Route 53 请求交给 FakeRoute53，Cloudflare 请求交给 FakeCloudflare（按区域 ID 分发，
 * 默认区域之外的区域放在 route53Zones / cloudflareZones 中），节点健康检查按 IP 返回预设结果，其余请求视为金丝雀。
 */
final class FakeNetwork
{
    public FakeRoute53 $route53;
    public FakeCloudflare $cloudflare;
    /** @var array<string,FakeRoute53> 其他托管区域：区域 ID => 区域 */
    public array $route53Zones = [];
    /** @var array<string,FakeCloudflare> 其他 Cloudflare 区域：区域 ID => 区域 */
    public array $cloudflareZones = [];
    /** @var array<string,true> 不可达的节点 IP */
    public array $unreachable = [];
    /** @var array<string,int> 节点 IP => HTTP 状态码（默认 200） */
    public array $status = [];
    /** @var array<string,bool> 协议族 => 金丝雀是否不可达 */
    public array $canaryDown = ['v4' => false, 'v6' => false];
    /** @var string[] 收到健康检查请求的 IP */
    public array $probed = [];
    /** @var array[] 通过 SES 发出的邮件（请求 JSON） */
    public array $emails = [];
    public bool $sesFails = false;
    /** @var Request[] 发往 heartbeat.test 的心跳请求 */
    public array $heartbeats = [];

    public function __construct(?FakeRoute53 $route53 = null)
    {
        $this->route53 = $route53 ?? new FakeRoute53();
        $this->cloudflare = new FakeCloudflare();
    }

    public function addRoute53Zone(string $zoneId, string $zoneName): FakeRoute53
    {
        return $this->route53Zones[$zoneId] = new FakeRoute53($zoneId, $zoneName);
    }

    public function addCloudflareZone(string $zoneId, string $zoneName): FakeCloudflare
    {
        return $this->cloudflareZones[$zoneId] = new FakeCloudflare($zoneId, $zoneName);
    }

    public function __invoke(Request $request)
    {
        $host = (string) parse_url($request->url, PHP_URL_HOST);
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        if ($host === 'route53.amazonaws.com') {
            $zone = preg_match('#/hostedzone/([^/]+)#', $path, $m) ? ($this->route53Zones[$m[1]] ?? null) : null;
            return ($zone ?? $this->route53)($request);
        }
        if ($host === 'api.cloudflare.com') {
            $zone = preg_match('#/zones/([^/]+)#', $path, $m) ? ($this->cloudflareZones[$m[1]] ?? null) : null;
            return ($zone ?? $this->cloudflare)($request);
        }
        if (preg_match('/^email\.[a-z0-9-]+\.amazonaws\.com$/', $host)) {
            if ($this->sesFails) {
                return new Response(500, ['x-amzn-ErrorType' => 'InternalFailure'], '{"message":"boom"}');
            }
            $this->emails[] = json_decode($request->body, true);
            return new Response(200, [], '{"MessageId":"m-' . count($this->emails) . '"}');
        }
        if ($host === 'heartbeat.test') {
            $this->heartbeats[] = $request;
            return new Response(204);
        }
        if ($request->resolve !== []) {
            $parts = explode(':', $request->resolve[0], 3);
            $ip = trim($parts[2], '[]');
            $this->probed[] = $ip;
            if (isset($this->unreachable[$ip])) {
                return new HttpException("GET {$request->url}: Failed to connect to {$ip}");
            }
            return new Response($this->status[$ip] ?? 200, [], 'ok', 0.01);
        }
        if ($this->canaryDown[$request->ipVersion ?? 'v4'] ?? false) {
            return new HttpException("GET {$request->url}: Couldn't connect to server");
        }
        return new Response(204, [], '', 0.01);
    }
}
