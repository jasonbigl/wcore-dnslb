<?php

namespace Wcore\Dnslb\Config;

/**
 * 一个发布区域：托管区域 ID 加对外名称。同一服务的所有区域发布同一批节点。
 */
final class DnsZone
{
    /** Route 53 托管区域 ID（不带 /hostedzone/ 前缀）或 Cloudflare 区域 ID */
    public string $zoneId;
    /** 对外服务的域名，不带结尾点号；可以是通配符，如 *.example.com */
    public string $name;
    /** name 是否为通配符域名 */
    public bool $wildcard;
    /** Route 53：第二层记录名模板，例如 "{pool}.pool.app.example.com" */
    public string $poolDomain = '';

    /**
     * Route 53：某个池的第二层记录名。
     */
    public function poolRecordName(string $pool): string
    {
        return str_replace('{pool}', $pool, $this->poolDomain);
    }
}
