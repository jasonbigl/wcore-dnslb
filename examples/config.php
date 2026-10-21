<?php

/*
 * dnslb 配置示例。一个配置文件对应一个服务（一个域名）。
 * 相对路径以本文件所在目录为基准。
 */

return [
    // 服务名：用于节点 ID、云厂商标签；小写字母数字和单个连字符，最长 20 个字符
    'service' => 'web',

    // SQLite 状态文件
    'state' => '/var/lib/dnslb/web.sqlite',

    // level: debug / info / warning / error；file 为 null 时输出到标准输出。创建 Dnslb 时传入 logger 则改用该 logger
    'log' => ['level' => 'info', 'file' => null],

    // true：只计算并记录要执行的操作，不调用任何写接口。也可以在调用时传 $dryRun = true
    'dry_run' => false,

    // Route 53、EC2、SES 共用的 AWS 密钥（都不使用时可以省略，例如 Cloudflare DNS + Linode，见 config-cloudflare.php）
    'aws' => [
        'key' => getenv('DNSLB_AWS_KEY') ?: 'AKIAXXXXXXXXXXXXXXXX',
        'secret' => getenv('DNSLB_AWS_SECRET') ?: 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ],

    // 云厂商账号，名称在 pools.*.launch.*.provider 中引用
    'providers' => [
        'linode' => ['driver' => 'linode', 'token' => getenv('DNSLB_LINODE_TOKEN') ?: 'xxxx'],
        'aws' => ['driver' => 'ec2'],
    ],

    'dns' => [
        // route53（默认）：按延迟就近解析到最近区域的池；cloudflare：所有池的节点轮询，见 config-cloudflare.php
        'provider' => 'route53',
        'zone_id' => 'Z0123456789ABCDEFGHIJ',
        // 对外服务的域名：每个池一条 latency 记录。也可以是通配符，如 '*.example.com'
        // （此时 pool_domain 默认为 {pool}.dnslb-pool.example.com，并且必须配置 health_check.host）
        'name' => 'app.example.com',
        // 每个池的节点记录名，必须以 {pool}. 开头
        'pool_domain' => '{pool}.pool.app.example.com',
        'ttl' => 60,
        // 全局 IPv6 开关：开启后每个节点都必须有 IPv6 地址，探测机也必须有 IPv6 出口
        'ipv6' => true,
        // multivalue：每次解析返回最多 8 个 IP；weighted：每次返回 1 个，按 launch 中的 weight 分配
        'pool_routing' => 'multivalue',
    ],

    'health_check' => [
        'scheme' => 'https',
        'port' => 443,
        'path' => '/health',
        'host' => 'app.example.com',            // SNI 与 Host 头，默认取 dns.name
        'verify_tls' => true,
        'expect_status' => [200, 299],
        'expect_body' => null,                  // 可选：响应中必须包含的字符串
        'timeout' => 5,
        'interval' => 10,                       // 每轮间隔（秒）
        'concurrency' => 50,
        'fall' => 3,                            // 连续失败 3 次从 DNS 摘除
        'rise' => 2,                            // 连续成功 2 次加回 DNS
        // 探测机自检地址：全部不通时视为探测机网络故障，跳过本轮
        'canary_urls' => [
            'https://www.google.com/generate_204',
            'https://www.cloudflare.com/cdn-cgi/trace',
        ],
        'global_freeze_ratio' => 0.5,           // 超过一半节点同时失败：冻结 DNS 变更并告警
        'pool_failover_after' => 180,           // 仅 Route 53：整池失败持续 180 秒后删除该区域 latency 记录
    ],

    // 新节点最长预热时间（秒），超时视为失败并销毁
    'warmup' => ['timeout' => 900],

    // 缩容排空：从 DNS 摘除后至少等 min 秒，最多等 max 秒再删除机器
    'drain' => [
        'min' => 300,
        'max' => 1800,
        // 可选：流量低于阈值即可提前删除
        'idle' => [
            'source' => 'grafana',
            'query' => 'sum by (node_id) (rate(nginx_http_requests_total{service="web"}[2m]))',
            'below' => 1,
        ],
    ],

    'autoheal' => [
        'enabled' => true,
        'unhealthy_for' => 300,                 // 在线节点持续不健康 300 秒后替换
        'max_unhealthy_ratio' => 0.5,           // 池内超过一半不健康视为系统性故障，只告警不替换
        'max_replacements_per_hour' => 5,
        'max_warmup_failures' => 3,             // 同一池连续 3 台预热失败则暂停该池扩容
        'pause_for' => 3600,
    ],

    // Prometheus 兼容的指标数据源（Grafana Cloud、自建 Prometheus、VictoriaMetrics 等）
    'metric_sources' => [
        'grafana' => [
            'driver' => 'prometheus',
            'url' => 'https://prometheus-prod-xx-prod-xx-xxx.grafana.net/api/prom',
            'username' => '123456',
            'password' => getenv('DNSLB_GRAFANA_READ_TOKEN') ?: 'glc_xxx',
        ],
    ],

    'scaling' => [
        'policy' => 'step',
        'step' => 1,                            // 每次扩 / 缩的台数
        'sustain' => 300,                       // 指标持续超过阈值的秒数
        'cooldown_out' => 600,
        'cooldown_in' => 900,
        'min_coverage' => 0.5,
        // 查询必须按节点返回并带 node_id 标签。任一指标超过 scale_out_above 即扩容，全部低于 scale_in_below 才缩容
        'metrics' => [
            'cpu' => [
                'source' => 'grafana',
                'query' => '100 * avg by (node_id) (1 - rate(node_cpu_seconds_total{mode="idle",service="web"}[5m]))',
                'aggregate' => 'avg',
                'scale_out_above' => 70,
                'scale_in_below' => 30,
            ],
        ],
    ],

    // 建机时使用的 user-data / cloud-init 模板
    // 内置占位符：{{node_id}} {{service}} {{pool}} {{provider}} {{region}} {{latency_region}}
    'user_data' => [
        'template' => 'cloud-init.yaml',
        'vars' => [
            'cert_center_url' => 'https://certs.example.com',
            'grafana_remote_write_url' => 'https://prometheus-prod-xx-prod-xx-xxx.grafana.net/api/prom/push',
            'grafana_username' => '123456',
            'grafana_write_token' => getenv('DNSLB_GRAFANA_WRITE_TOKEN') ?: 'glc_xxx',
        ],
    ],

    'notify' => [
        'driver' => 'ses',
        'region' => 'us-east-1',
        'from' => 'dnslb@example.com',
        'to' => ['ops@example.com'],
        'throttle' => 3600,                     // 同一告警每小时最多一封
    ],

    // 控制器心跳，配合外部监控在控制器失联时告警。此处推送到 Grafana Cloud 的 Influx 接口
    'heartbeat' => [
        'url' => 'https://influx-prod-xx-prod-xx-xxx.grafana.net/api/v1/push/influx/write',
        'method' => 'POST',
        'auth' => ['123456', getenv('DNSLB_GRAFANA_WRITE_TOKEN') ?: 'glc_xxx'],
        'body' => 'dnslb_heartbeat,service={{service}},cmd={{cmd}} up=1',
    ],

    'pools' => [
        'tokyo' => [
            'latency_region' => 'ap-northeast-1',
            'min' => 2,
            'max' => 10,
            // 按顺序尝试，前一个创建失败（如容量不足）则用下一个
            'launch' => [
                [
                    'provider' => 'linode',
                    'region' => 'ap-northeast',
                    'type' => 'g6-dedicated-2',
                    'image' => 'linode/ubuntu24.04',
                    'firewall_id' => 12345,
                    'authorized_keys' => ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample ops@example.com'],
                ],
                [
                    'provider' => 'aws',
                    'region' => 'ap-northeast-1',
                    'instance_type' => 'c7i.large',
                    'ami' => 'ami-0123456789abcdef0',
                    'subnet_id' => 'subnet-0123456789abcdef0',
                    'security_group_ids' => ['sg-0123456789abcdef0'],
                    'key_name' => 'ops',
                    'volume_size' => 20,
                ],
            ],
        ],
        'frankfurt' => [
            'latency_region' => 'eu-central-1',
            'min' => 1,
            'max' => 6,
            'launch' => [
                [
                    'provider' => 'aws',
                    'region' => 'eu-central-1',
                    'instance_type' => 'c7i.large',
                    'ami' => 'ami-0fedcba9876543210',
                    'subnet_id' => 'subnet-0fedcba9876543210',
                    'security_group_ids' => ['sg-0fedcba9876543210'],
                ],
            ],
            // 覆盖全局 scaling 中的部分配置
            'scaling' => [
                'metrics' => ['cpu' => ['scale_out_above' => 80]],
            ],
        ],
    ],
];
