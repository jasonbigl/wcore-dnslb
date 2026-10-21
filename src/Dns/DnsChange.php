<?php

namespace Wcore\Dnslb\Dns;

/**
 * 一条 DNS 变更（Route 53 的 Change、Cloudflare 的 RecordChange）。
 */
interface DnsChange
{
    /**
     * 便于日志阅读的描述，例如 "CREATE A tokyo.pool.app.example.com [web-tokyo-a1b2c3] 1.2.3.4"。
     */
    public function describe(): string;
}
