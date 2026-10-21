# wcore-dnslb

基于 DNS 的负载均衡与自动伸缩控制器，支持 AWS Route 53（按延迟就近）和 Cloudflare（轮询，可开启代理）。纯 PHP（7.4 ~ 8.x）、零 composer 运行时依赖，以 composer 包的形式在多个项目中复用。

- Route 53：用户按延迟就近解析到最近区域的节点（latency 路由 + 每个区域一组多值回答记录）
- Cloudflare：所有区域的节点在同一个域名下轮询，适合不需要就近访问、但需要健康检查和故障恢复的服务；开启代理（橙云）时摘除节点几秒内生效
- 自建并行健康检查，故障节点自动从 DNS 摘除，不使用（也不支付）DNS 服务商的健康检查
- 按 Prometheus 兼容指标（Grafana Cloud 等）自动扩缩容，节点在 Linode / AWS EC2 上通过 cloud-init 自动部署
- 缩容先从 DNS 摘除，排空一段时间后再删除机器，避免 DNS 缓存导致用户访问到已删除的节点
- 自动修复故障节点，SES 邮件告警，心跳推送用于监控控制器本身
- 支持通配符域名（如 `*.example.com`）、IPv6、从现有记录无中断切换

设计细节见 [docs/design.md](docs/design.md)。

## 工作原理

Route 53（`dns.provider` 为 `route53`，默认）：

```
app.example.com（或 *.example.com）   Latency Alias 记录，每个区域一条（A / AAAA）
 ├─ ap-northeast-1 → tokyo.pool.app.example.com       多值回答记录，每个健康节点一条
 └─ eu-central-1   → frankfurt.pool.app.example.com
```

Cloudflare（`dns.provider` 为 `cloudflare`）：

```
api.example.com（或 *.example.com）   每个健康节点一条 A / AAAA 记录（所有池），备注 "dnslb node <节点 ID>"
```

开启代理时，用户连接最近的 Cloudflare 边缘，边缘在这些记录中随机选择节点回源，失败时自动重试其他节点。

控制器运行在一台常驻的小机器上（EC2 或 Linode 均可），通过 PHP 调用本包。由调度器（例如 Laravel scheduler）每分钟调用两个方法：

| 方法 | 职责 |
|---|---|
| `health()` | 每 10 秒并行探测所有节点的健康检查地址，连续失败摘除、连续成功加回，并同步 DNS |
| `tick()` | 推进节点生命周期 → 自动修复 → 维持 min / max → 按指标扩缩容 → 同步 DNS → 发送通知 → 心跳 |

节点生命周期：建机（PROVISIONING）→ 启动（BOOTING）→ 预热（WARMING，健康检查通过才上线）→ 在线（IN_SERVICE）→ 排空（DRAINING）→ 删除（TERMINATED）。

## 环境要求

- PHP 7.4 及以上，扩展：curl、json、pdo_sqlite、simplexml
- 控制器机器：能访问各节点的健康检查端口；开启 IPv6（`dns.ipv6`）时必须有 IPv6 出口
- 节点镜像支持 cloud-init（Linode 需选择支持 Metadata 服务的机房和镜像）
- DNS：AWS Route 53 托管区域，或 Cloudflare 上的区域（域名的 NS 已切换到 Cloudflare）
- AWS 账号：使用 Route 53、EC2 或 SES 时需要
- 可选：Linode API token、Grafana Cloud（或其他 Prometheus 兼容服务）

## 安装

```bash
composer config repositories.dnslb vcs https://github.com/jasonbigl/wcore-dnslb.git
composer require wcore/dnslb:dev-main
```

通过 `Wcore\Dnslb\Dnslb` 在 PHP 代码中使用（见「使用」）。

## 上手步骤

### 1. 准备凭证

- **AWS**：创建一个 IAM 用户，使用 [docs/iam-policy.json](docs/iam-policy.json) 中的最小权限策略（把 `ZONE_ID`、`ACCOUNT_ID`、`SERVICE`、`SES_REGION` 替换成实际值）。
  - EC2 只能创建带 `dnslb:service=<服务名>` 标签的实例，也只能删除带这个标签的实例。
  - 配置了 `iam_instance_profile` 时，还需要对该角色的 `iam:PassRole` 权限。
  - `route53:TestDNSAnswer` 只有 `tools/verify-route53.php` 用到。
- **Cloudflare**（使用 Cloudflare DNS 时）：创建 API 令牌，只授予该区域的 `Zone - DNS - Edit` 和 `Zone - Zone - Read`。
- **Linode**：创建 Personal Access Token，权限：Linodes 读写、Images 只读；使用 `firewall_id` 时还需要 Firewalls 读写。
- **Grafana Cloud**：一个只读 token（控制器查询指标）和一个只写 token（节点上报指标、控制器推送心跳）。
- **SES**：验证发件域名或地址，并申请移出沙箱（否则只能发给已验证的地址）。

### 2. 编写配置

复制 [examples/config.php](examples/config.php)（Route 53）或 [examples/config-cloudflare.php](examples/config-cloudflare.php)（Cloudflare），每个服务（域名）一个配置文件，例如 `/etc/dnslb/web.php`。示例中每一项都有注释。要点：

- `pools`：`launch` 按顺序列出建机方式，前一种被明确拒绝（容量不足、配额等）自动用下一种；结果未知（超时、限流、5xx）时先在同一方式上确认，2 分钟后仍查不到实例才换下一种，避免在两处各建一台。使用 Route 53 时每个池对应一个 AWS 区域（`latency_region`，Linode 机房映射到地理上最近的 AWS 区域）；使用 Cloudflare 时不需要 `latency_region`，池只用于分区域建机和扩缩容。
- `scaling.metrics`：PromQL 查询必须按节点返回，并带 `node_id` 标签，例如 `... by (node_id)`。
- `user_data.template`：cloud-init 模板，参考 [examples/cloud-init.yaml](examples/cloud-init.yaml)。内置占位符 `{{node_id}}`、`{{service}}`、`{{pool}}`、`{{provider}}`、`{{region}}`、`{{latency_region}}`，其余在 `user_data.vars` 中定义。节点需要把指标带上 `node_id` 标签上报。
- 通配符域名：`dns.name` 写成 `'*.example.com'`，并必须配置 `health_check.host` 为一个通配符覆盖的具体域名（用于 SNI 和 Host 头）。
- 凭证建议通过 `getenv()` 读取，不要提交到代码仓库。

### 3. 检查与演练

```php
$lb = Dnslb::fromConfigFile('/etc/dnslb/web.php');
foreach ($lb->check()->items as $item) {      // 只读：凭证、DNS、建机参数、指标查询、SES、探测机出口
    echo "[{$item->status}] {$item->name}：{$item->message}\n";
}
$lb->tick(true);                              // 演练：日志中输出将要创建的机器和 DNS 变更，不做任何修改
```

### 4. 定时运行

每分钟调用一次 `tick()` 和 `health()`，Laravel 的写法见下文「Laravel 示例」。`health()` 每次运行约 55 秒，两者要并行调度，不能串行。两个方法各自有进程锁，重复启动不会并发执行。多个服务就各自一份配置、各自调度。

### 5. 从现有记录切换（如果域名已有记录）

对外域名上已有不由 dnslb 管理的 A / AAAA / CNAME 记录时，dnslb 不会修改这个名称（Route 53 只发布池记录，Cloudflare 不发布任何记录），并在日志中提示。待 `status()` 显示各池已有在线节点后：

```php
foreach ($lb->takeover(true)->changes as $change) {   // 预览将要删除和创建的记录
    echo $change->describe(), "\n";
}
$lb->takeover();                                      // 替换现有记录，解析不中断
```

- Route 53：删除旧记录和创建 latency 记录在同一个原子批次中完成。
- 未开启 IPv6（`dns.ipv6 = false`）时，旧的 AAAA 记录也会被删除：保留的话双栈客户端会优先走 IPv6，继续访问旧服务器。之后只有纯 IPv6 的客户端无法访问（Cloudflare 代理模式下边缘本身提供 IPv6，不受影响）。预览时请确认要删除的记录。
- Cloudflare：先添加节点记录，等待几秒传播后再删除旧的 A / AAAA 记录，期间新旧记录一起轮询。旧记录是 CNAME 时（不能与 A 记录并存）只能在同一批次中先删后增，各边缘节点可能有几秒不一致。

### 6. 监控控制器本身

控制器挂掉时，健康检查和邮件通知也会停止，所以需要外部监控心跳。`tick` 和 `health` 每次正常运行结束都会发送心跳（`heartbeat` 配置）；`tick` 有步骤失败（例如 DNS 同步失败、状态库无法写入）时不发送。`tick` 和 `health` 要分别监控，否则其中一个停止时另一个的心跳会让监控保持正常：

- **Grafana Cloud**：按示例配置推送 Influx 行协议 `dnslb_heartbeat,service=web,cmd=tick up=1`，然后建一条告警规则。指标名一般为 `dnslb_heartbeat_up`（measurement_field），可以先在 Explore 中确认。规则示例：
  ```
  absent_over_time(dnslb_heartbeat_up{service="web", cmd="tick"}[5m])
  absent_over_time(dnslb_heartbeat_up{service="web", cmd="health"}[5m])
  ```
- **healthchecks.io / Uptime Kuma 等**：`heartbeat.url` 填它们的 ping 地址（默认 GET）。地址能用 `{{cmd}}` 区分时为 `tick` 和 `health` 各建一个检查项，例如 healthchecks.io 的 slug 地址 `https://hc-ping.com/<ping key>/web-{{cmd}}`；只能配一个地址时（如 Uptime Kuma 的推送令牌），`health` 单独停止不会被发现。

## 使用

```php
use Wcore\Dnslb\Dnslb;

$lb = Dnslb::fromConfigFile('/etc/dnslb/web.php', ['logger' => $psrLogger]);   // logger 可选
// 或直接用配置数组：Dnslb::fromConfigArray($configArray, $baseDir)，$baseDir 是配置中相对路径的基准目录
```

| 方法 | 返回 | 说明 |
|---|---|---|
| `tick(bool $dryRun = false)` | `bool` | 每分钟调度：生命周期、自动修复、扩缩容、DNS、通知、心跳。上一个 tick 未结束时返回 false |
| `health(int $duration = 55, bool $once = false, bool $dryRun = false)` | `int` | 每分钟调度：每 `health_check.interval` 秒一轮健康检查，返回完成的轮数 |
| `status()` | `array` | 各池、节点状态和最近事件（只读本地状态库），结构见 `Operations\StatusReport` |
| `check()` | `CheckReport` | 校验配置和外部服务（只读）。`items` 为检查项（`status` 为 ok / warn / fail、`name`、`message`），`passed()` 表示没有失败项 |
| `takeover(bool $dryRun = false)` | `SyncResult` | 无中断地接管对外域名上的现有记录。`changes` 为变更列表（`describe()` 可读描述），`changeIds` 为 Route 53 变更 ID（Cloudflare 为空） |
| `scale(string $pool, int $delta, bool $dryRun = false)` | `ScaleResult` | 手动扩缩容。`requested` / `allowed`（受 min / max 限制后的台数，缩容后剩下的健康节点也不少于 min）、`created` / `failed` / `drained` 为节点列表 |
| `drain(string $nodeId, bool $dryRun = false)` | `Node` | 立即从 DNS 摘除并排空指定节点 |
| `replace(string $nodeId, bool $dryRun = false)` | `Node` | 先建替补（返回替补节点），上线后排空指定节点 |
| `rollout(?string $pool = null, int $batch = 1, bool $dryRun = false)` | `RolloutResult` | 发布新版本：用新机器逐台替换现有节点，由之后的 tick 逐步完成（见下方「发布新版本」）。`nodes` 为池 => 待替换节点，`warnings` 为需要注意的情况 |
| `cancelRollout(?string $pool = null)` | — | 停止发布；已开始的替换继续完成，其余旧节点保持不变 |
| `pause(?string $pool = null)` / `resume(?string $pool = null)` | — | 暂停 / 恢复自动增删机器（维护时用；健康检查和 DNS 摘除照常）。`resume` 同时解除连续启动失败造成的自动暂停；`$pool` 为 null 时作用于所有池 |
| `rebuildState(bool $dryRun = false)` | `RebuildResult` | 状态库丢失后，根据云上实例和 DNS 记录重建。`restored` 为恢复的节点，`warnings` 为需要确认的情况 |
| `cleanupOrphans(bool $dryRun = true)` | `OrphanCleanupResult` | 删除孤儿实例：云上带本服务标签、但连续 15 分钟没有节点引用的实例（tick 每 10 分钟检查并告警，`status()` 的 `orphans` 中列出）。**默认只预览**，`orphans` 为孤儿列表；传 false 删除，`terminated` / `failed` 为结果。状态库从备份恢复后先 `rebuildState()`，不要直接删除 |
| `app()` | `Application` | 底层组件（高级用法），例如 `$lb->app()->nodes()->live()` |

- 节点对象（`Wcore\Dnslb\State\Node`）的常用属性：`nodeId`、`pool`、`state`、`provider`、`providerRegion`、`instanceId`、`ipv4`、`ipv6`、`lastError`。
- 参数错误抛出 `InvalidArgumentException`（例如池或节点不存在）；操作无法执行抛出 `Operations\OperationException`（例如节点不在线、替补建机失败）；外部服务异常原样抛出。
- 修改节点的方法持有 tick 锁，不会与 `tick()` 或其他手动操作同时执行（最多等 60 秒）。
- `$dryRun = true`：在状态库的内存副本上执行，云厂商写操作和 DNS 变更只记录日志，不修改任何东西。
- `logger` 可以是任意 PSR-3 风格的 logger，消息带 `[dnslb:<操作>]` 前缀；不传时按配置的 `log` 段输出到标准输出或文件。
- 每次调用都创建新的内部实例，`Dnslb` 对象可以在常驻进程中长期持有。
- 只能在一台机器上运行（锁和状态库都在本机），多台服务器部署时要固定在其中一台。

### 发布新版本

节点不在原地更新：新机器开机时自动部署"当前版本"（由 cloud-init / 你的部署脚本实现），dnslb 负责用新机器替换旧机器。

1. 发布流程先更新"当前版本"（例如镜像 tag），确认新开的机器能拿到新版本；
2. 调用 `$lb->rollout()`（或 `rollout('dallas')` 只发布一个池，`rollout(null, 2)` 每个池同时替换 2 台）。

调用时池中已有的节点（包括尚未上线的）都标记为旧版本，之后每次 tick：

- 每个池同时最多替换 `batch` 台：先建替补，替补通过健康检查上线后才排空旧节点，在线节点数不会减少，替补不占 `max` 名额；
- 发布期间因扩容、自动修复新建的节点都是新版本，不会被替换；缩容优先排空旧节点；替换不会让扩缩容等待；
- 手动 `pause()` 或健康检查冻结时等待；
- 新版本有问题、新机器通不过健康检查时：连续 `autoheal.max_warmup_failures` 台未能上线后，该池自动暂停，发布停止并发送 CRITICAL 邮件，剩余旧节点继续服务。修复后先 `resume()` 再重新 `rollout()`；
- 全部旧节点下线后记录 `rollout_completed` 事件。进度见 `status()` 中池的 `rollout`（`total`、`remaining`、`replacing`），节点的 `outdated` 表示等待替换。

耗时约为"每个池的节点数 ÷ batch × 单台从建机到上线的时间"，旧机器在排空（`drain.max`）后才删除。发布进行中再次调用 `rollout()` 会重新开始，已替换上的节点也会再替换一次。健康检查是唯一的上线门槛，健康检查路径应能反映新版本是否可用。

### Laravel 示例

```php
// AppServiceProvider::register()
$this->app->singleton(Dnslb::class, function () {
    return Dnslb::fromConfigFile(config('services.dnslb.config'), ['logger' => Log::channel('dnslb')]);
});
```

```php
// app/Console/Commands/DnslbTick.php（health 同理，调用 $lb->health()）
class DnslbTick extends Command
{
    protected $signature = 'dnslb:tick {--dry-run}';

    public function handle(Dnslb $lb): int
    {
        return $lb->tick((bool) $this->option('dry-run')) ? 0 : 1;
    }
}
```

```php
// 调度：health 每次运行约 55 秒，必须 runInBackground()，否则会阻塞同一分钟的其他任务。
// 两个命令自带进程锁，不需要 withoutOverlapping()。
$schedule->command('dnslb:tick')->everyMinute()->runInBackground();
$schedule->command('dnslb:health')->everyMinute()->runInBackground();
```

```php
// 手动扩缩容，例如 php artisan dnslb:scale dallas 1
class DnslbScale extends Command
{
    protected $signature = 'dnslb:scale {pool} {delta} {--dry-run}';

    public function handle(Dnslb $lb): int
    {
        $result = $lb->scale($this->argument('pool'), (int) $this->argument('delta'), (bool) $this->option('dry-run'));
        if ($result->allowed !== $result->requested) {
            $this->warn("受 min / max 限制，只执行 {$result->allowed} 台");
        }
        foreach ($result->created as $node) {
            $this->info("已创建 {$node->nodeId}（{$node->provider}/{$node->providerRegion}）");
        }
        foreach ($result->failed as $node) {
            $this->error("建机失败：{$node->lastError}");
        }
        foreach ($result->drained as $node) {
            $this->info("排空 {$node->nodeId}");
        }
        return $result->failed === [] ? 0 : 1;
    }
}
```

```php
// 发布新版本，例如在发布流程更新版本号之后执行 php artisan dnslb:rollout
class DnslbRollout extends Command
{
    protected $signature = 'dnslb:rollout {pool?} {--batch=1} {--dry-run}';

    public function handle(Dnslb $lb): int
    {
        $result = $lb->rollout($this->argument('pool'), (int) $this->option('batch'), (bool) $this->option('dry-run'));
        foreach ($result->nodes as $pool => $nodes) {
            $this->info("池 {$pool}：" . count($nodes) . ' 台节点待替换');
        }
        foreach ($result->warnings as $warning) {
            $this->warn($warning);
        }
        return 0;
    }
}
```

```php
// 状态
foreach ($lb->status()['pools'] as $pool) {
    $this->line("池 {$pool['name']}" . ($pool['latency_region'] ? "（{$pool['latency_region']}）" : ''));
    $this->table(['节点', '状态', 'IPv4', 'v4', 'v6'], array_map(fn ($n) => [
        $n['node_id'], $n['state'], $n['ipv4'], $n['v4_healthy'] ? 'up' : 'down', $n['v6_healthy'] ? 'up' : 'down',
    ], $pool['nodes']));
}
```

```php
// takeover：先预览，确认后再提交
foreach ($lb->takeover(true)->changes as $change) {
    $this->line($change->describe());
}
if ($this->confirm('确认切换 DNS？')) {
    $result = $lb->takeover();
    $this->info('已提交：' . implode(', ', $result->changeIds));
}
```

```php
// 孤儿实例（收到 orphan_instance 告警后）：先预览，确认后再删除
$preview = $lb->cleanupOrphans();
foreach ($preview->orphans as $orphan) {
    $this->line("{$orphan->provider}/{$orphan->region} {$orphan->instanceId}（节点 ID {$orphan->nodeId}）");
}
if ($preview->orphans !== [] && $this->confirm('确认删除这些实例？')) {
    $result = $lb->cleanupOrphans(false);
    foreach ($result->failed as $instanceId => $error) {
        $this->error("{$instanceId} 删除失败：{$error}");
    }
}
```

## 配置参考

| 配置 | 默认值 | 说明 |
|---|---|---|
| `dns.provider` | route53 | `route53`：按延迟就近；`cloudflare`：所有池的节点轮询（需要 `cloudflare.api_token`，池不配置 `latency_region`） |
| `dns.ttl` | 60 | 节点记录 TTL。Cloudflare 代理记录固定为自动，仅 DNS 时最小 60（企业版 30） |
| `dns.ipv6` | false | 全局 IPv6 开关；开启后每个节点都必须有 IPv6，探测机也必须有 IPv6 出口。Cloudflare 代理模式下客户端始终可用 IPv6 访问边缘，节点不需要 IPv6 |
| `dns.pool_routing` | multivalue | 仅 Route 53。`multivalue`：每次返回最多 8 个 IP，客户端可自行重试；`weighted`：按 `launch[].weight` 加权，每次返回 1 个 |
| `dns.pool_domain` | `{pool}.pool.<name>`（通配符时 `{pool}.dnslb-pool.<base>`） | 仅 Route 53。池记录名，必须专用于 dnslb |
| `dns.proxied` | true | 仅 Cloudflare。`true`：代理（橙云）；`false`：仅 DNS（灰云） |
| `health_check.interval` | 10 | 每轮间隔（秒） |
| `health_check.fall` / `rise` | 3 / 2 | 连续失败多少次摘除 / 连续成功多少次加回 |
| `health_check.global_freeze_ratio` | 0.5 | 同一轮超过该比例的在线节点失败时冻结，不摘除任何节点 |
| `health_check.pool_failover_after` | 180 | 仅 Route 53：整池故障持续多久后撤下该区域，流量转到其他区域。Cloudflare 下整池故障时直接撤下，由其他池承接 |
| `warmup.timeout` | 900 | 从建机到上线的最长时间 |
| `drain.min` / `drain.max` | 300 / 1800 | 排空时间，从确认节点已不在 DNS 中时算起：配置了 `drain.idle` 时，过了 min 且流量低于阈值即可删除；否则等满 max |
| `autoheal.unhealthy_for` | 300 | 在线节点持续不健康多久后替换 |
| `autoheal.max_unhealthy_ratio` | 0.5 | 所有在线节点中不健康比例超过此值视为系统性故障，只告警不替换 |
| `autoheal.max_replacements_per_hour` | 5 | 每小时自动替换上限 |
| `autoheal.max_warmup_failures` / `pause_for` | 3 / 3600 | 连续多少台新节点未能上线就暂停该池建机，以及暂停多久 |
| `scaling.step` | 1 | 每次扩 / 缩台数 |
| `scaling.sustain` | 300 | 指标持续超过阈值多久才动作 |
| `scaling.cooldown_out` / `cooldown_in` | 600 / 900 | 扩容冷却 / 缩容冷却（距上次任何扩缩容） |
| `scaling.min_coverage` | 0.5 | 有指标数据的在线节点比例低于此值时不做决策 |
| `notify.throttle` | 3600 | 同一告警最小发送间隔 |

池可以用 `pools.<池>.scaling` 覆盖全局 `scaling` 的任意项；把某个指标设为 `null` 可在该池禁用它。

## 注意事项

- **DNS 负载均衡生效慢**：新节点只能接到新解析的流量，已缓存 IP 的客户端在 TTL 内（部分运营商 DNS 更久）仍访问旧节点。扩容要留出冷却时间，缩容依靠排空期覆盖缓存。
- **单探测点**：健康检查只从控制器一个位置发起。金丝雀地址检测探测机自身网络，全局冻结防止探测机线路问题导致大面积误摘；代价是同一轮超过半数节点真实故障时，故障节点会留在 DNS 中，需要人工介入（见下方「后续迭代」）。
- **IMDSv2**：EC2 实例强制使用 IMDSv2，cloud-init 脚本读取实例元数据时需要先申请令牌。
- **按指标不会缩容到 0 台**：缩容前会预测剩余节点的负载，剩 0 台时预测不成立。
- **节点上报指标**必须带 `node_id` 标签。查询返回同一节点多条序列，或序列缺少 `node_id` 标签时，该指标视为不可用，不做决策。
- **改名或替换全部池**：建议先加新池，新节点上线后再删旧池。一次换掉全部池也不会断解析：没有可以发布的节点时，对外名称保留现有记录（发出 `dns_empty_held` 告警），旧节点继续服务到新节点上线后再切换。反过来，删掉全部池并不会让域名下线，记录和旧机器会一直保留，下线整个服务需要手动删除记录。

使用 Cloudflare 时：

- **不区分区域**：用户被随机分配到任一池的节点。开启代理时，Cloudflare 边缘随机选择节点回源，与用户所在区域无关。
- **代理模式下 DNS 缓存不再是问题**：客户端解析到的是 Cloudflare 的地址，摘除节点几秒内在边缘生效，`drain` 可以设得短一些（例如 60 ~ 300 秒）。仅 DNS 模式与 Route 53 一样受客户端缓存影响。
- **节点侧配置**（代理模式）：防火墙放行 [Cloudflare 的 IP 段](https://www.cloudflare.com/ips/)（以及控制器的健康检查），真实客户端 IP 从 `CF-Connecting-IP` 头读取；SSL/TLS 加密模式建议用 Full (strict)，节点证书需覆盖对外域名。代理只支持 HTTP/HTTPS 的标准端口（443、8443、80、8080 等）。
- **记录数量**：免费版 2024-09 之后添加的区域最多 200 条记录，每个节点占 1 ~ 2 条（A / AAAA）。
- **IPv6 地址**按规范形式比较（例如 `2001:DB8:0::2` 与 `2001:db8::2` 视为同一地址）。

## 开发

```bash
composer install
vendor/bin/phpunit
# PHP 7.4 上测试（需要 pdo_sqlite）
docker run --rm -v "$PWD":/app -w /app php:7.4-cli vendor/bin/phpunit
```

`tools/verify-route53.php` 和 `tools/verify-cloudflare.php` 在真实环境中用临时名称验证记录结构与同步逻辑（会自动清理，只操作没有现有记录的测试名称）。凭证读取环境变量 `AWS_KEY`、`AWS_SECRET`、`CF_TOKEN`，未设置时读取仓库根目录的 `.env`（已在 `.gitignore` 中）：

```bash
php tools/verify-route53.php --zone <ZoneId> --domain '*.dnslb-verify.example.com'
php tools/verify-cloudflare.php --zone <区域 ID> --domain '*.dnslb-verify.example.com'
```

Cloudflare 的验证使用通配符名称时，还会通过 DoH（Cloudflare，无法访问时改用 Google）解析新的子域名，确认代理记录解析到 Cloudflare 边缘、仅 DNS 记录解析到节点地址。

## 后续迭代

- **目标跟踪扩缩容模式**：第一版只支持固定步长（每次扩/缩 `step` 台）。后续增加目标跟踪模式，按 `ceil(当前台数 × 当前指标值 / 目标值)` 一次算出所需台数，并受每个池的 min/max 约束。
- **健康检查冻结优化**：目前同一轮超过 `global_freeze_ratio` 的在线节点失败就冻结，不摘除任何节点，直到比例回落；计算时也包含已被摘除的节点，故障逐个累积超过阈值时同样会冻结，之后新故障的节点会留在 DNS 中。可改进为：只统计本轮新出现的失败（原本健康的节点），并增加 `freeze_max`（例如 300 秒），金丝雀可达且冻结超时后视为真实故障、恢复正常摘除。
- **多探测点投票**：第一版健康检查只有控制器一个探测点，可能因探测机到某区域的网络问题误判。后续支持多台探测机各自探测、汇总结果投票决定节点健康状态。
