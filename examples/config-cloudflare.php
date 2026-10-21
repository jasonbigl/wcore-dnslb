<?php

/*
 * 使用 Cloudflare DNS 的配置示例：所有池的节点在同一个域名下轮询（不区分区域），开启代理（橙云）。
 * 适合不需要按区域就近访问、但需要健康检查和故障恢复的服务。
 * 这里只列出与 Route 53 不同的部分和最小必填项，其余配置（health_check、drain、autoheal、scaling、
 * notify、heartbeat 等）与 config.php 相同。
 */

return [
    'service' => 'api',
    'state' => '/var/lib/dnslb/api.sqlite',

    // Cloudflare API 令牌：只授予该区域的 Zone - DNS - Edit 和 Zone - Zone - Read
    'cloudflare' => [
        'api_token' => getenv('DNSLB_CF_TOKEN') ?: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ],

    // 只用 Linode、不发 SES 邮件时不需要 aws 段；使用 EC2 或 notify（SES）时仍需配置
    'providers' => [
        'linode' => ['driver' => 'linode', 'token' => getenv('DNSLB_LINODE_TOKEN') ?: 'xxxx'],
    ],

    'dns' => [
        'provider' => 'cloudflare',
        // 区域 ID：Cloudflare 控制台中域名概览页右下角的 Zone ID
        'zone_id' => '0123456789abcdef0123456789abcdef',
        // 对外服务的域名，也可以是通配符 '*.example.com'（此时必须配置 health_check.host）
        'name' => 'api.example.com',
        // true（默认）：代理（橙云）。摘除节点几秒内生效，边缘回源失败会自动换其他节点重试；
        // 节点 IP 不暴露，节点需要放行 Cloudflare 的 IP 段，真实客户端 IP 从 CF-Connecting-IP 头读取。
        // false：仅 DNS（灰云），解析直接返回节点 IP，摘除受客户端 DNS 缓存影响
        'proxied' => true,
        'ttl' => 60,                            // 仅 DNS 时的 TTL（最小 60，企业版 30）；代理记录固定为自动
        // 开启代理时，客户端始终可以用 IPv4 / IPv6 访问 Cloudflare；节点不需要 IPv6
        'ipv6' => false,
    ],

    'health_check' => [
        'path' => '/health',
        'fall' => 3,
        'rise' => 2,
    ],

    // 开启代理时 Cloudflare 边缘几秒内就不再回源到摘除的节点，排空时间可以短一些
    'drain' => ['min' => 60, 'max' => 300],

    'user_data' => [
        'template' => 'cloud-init.yaml',
        'vars' => [
            'cert_center_url' => 'https://certs.example.com',
            'grafana_remote_write_url' => 'https://prometheus-prod-xx-prod-xx-xxx.grafana.net/api/prom/push',
            'grafana_username' => '123456',
            'grafana_write_token' => getenv('DNSLB_GRAFANA_WRITE_TOKEN') ?: 'glc_xxx',
        ],
    ],

    // 不需要 latency_region：池只用于分区域建机和扩缩容，流量在所有池的节点间轮询
    'pools' => [
        'dallas' => [
            'min' => 1,
            'max' => 4,
            'launch' => [['provider' => 'linode', 'region' => 'us-central', 'type' => 'g6-standard-2', 'image' => 'linode/debian12']],
        ],
        'newark' => [
            'min' => 1,
            'max' => 4,
            'launch' => [['provider' => 'linode', 'region' => 'us-east', 'type' => 'g6-standard-2', 'image' => 'linode/debian12']],
        ],
    ],
];
