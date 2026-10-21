# dnslb 设计文档

基于 DNS（AWS Route 53 latency 路由，或 Cloudflare 轮询）的负载均衡与自动伸缩控制器。以 composer 包形式发布，纯 PHP、零运行时依赖，兼容 PHP 7.4 ~ 8.x。

## 1. 目标

- 用户按延迟就近访问各区域节点（Route 53 latency 路由）；或者不区分区域、在所有节点间轮询（Cloudflare，可开启代理）。
- 按区域根据监控指标（Prometheus 兼容数据源，如 Grafana Cloud）自动扩容、缩容。
- 支持 Linode 和 AWS EC2，通过 user-data / cloud-init 自动部署。
- 自建并行健康检查，节点故障时由控制器自己从 DNS 摘除，不使用 DNS 服务商的健康检查（节省费用）。
- 缩容时先从 DNS 摘除，排空一段时间后再删除机器，避免 DNS 缓存导致用户访问到已删除的节点。
- 自动修复故障节点；邮件通知（AWS SES）；心跳推送用于外部监控控制器本身。
- 所有区域、云厂商、指标都通过配置文件定义，不写死在代码里。

## 2. 约束与技术决策

| 项 | 决策 |
|---|---|
| PHP 版本 | 7.4 起，同时兼容 8.x |
| 依赖 | 运行时零 composer 依赖，只用 ext-curl / json / pdo_sqlite / simplexml；PHPUnit 仅作开发依赖 |
| AWS 调用 | 自己实现 SigV4 签名（官方 SDK 已不支持 PHP 8.1 以下） |
| AWS 凭证 | 只支持配置文件中的 key/secret；只在使用 Route 53、EC2 或 SES 时需要 |
| DNS 服务商 | `dns.provider`：`route53`（默认，按延迟就近）或 `cloudflare`（轮询）。两者共用节点选择规则（`NodeSelector`），通过 `DnsSync` 接口切换 |
| 配置格式 | PHP 数组文件；一个配置文件对应一个服务（一个域名），各自独立的 SQLite 和锁 |
| 状态存储 | SQLite（WAL 模式） |
| IPv6 | 全局开关；开启后所有池都必须提供 IPv6，v4 / v6 分别检查、分别记录 |
| 扩缩容策略 | 第一版只做固定步长，目标跟踪模式见 README「后续迭代」 |

## 3. Route 53 记录结构

```
app.example.com          Latency 记录，Alias，SetIdentifier=dnslb-{pool}，A 和 AAAA 各一条
 ├─ region=ap-northeast-1 → tokyo.pool.app.example.com
 └─ region=eu-central-1   → frankfurt.pool.app.example.com

tokyo.pool.app.example.com   多值回答记录（默认）或加权记录，每个节点一条
 ├─ SetIdentifier=web-tokyo-a1b2c3  A 1.2.3.4   TTL=60
 └─ SetIdentifier=web-tokyo-d4e5f6  A 5.6.7.8
```

- `dns.pool_routing`：`multivalue`（默认）或 `weighted`。多值回答每次返回最多 8 个 IP，客户端可在连接失败时换 IP 重试，大型递归 DNS 下分布也更均匀；代价是没有权重。`weighted` 模式下使用 launch 配置中的 `weight`。
- 记录上不挂 Route 53 健康检查，由控制器自己删除/加回记录。
- 某个池没有可用记录时，删除该区域的 latency 记录，流量转到次近区域；池恢复后重新创建。
- Linode 机房映射到地理上最近的 AWS region。
- 支持通配符域名：`dns.name` 可以是 `*.example.com`（只允许最左一级是 `*`），第一层 latency 记录建在通配符上。此时：
  - `pool_domain` 默认为 `{pool}.dnslb-pool.example.com`，避免与真实子域名冲突。按 DNS 通配符规则，`dnslb-pool.example.com` 这个名字本身不再匹配通配符。
  - `health_check.host` 必须显式配置为通配符覆盖的具体域名（例如 `health.example.com`），用于 SNI 和 Host 头。
  - Route 53 返回的名称中 `*` 写作 `\052`，读写时统一转换。
- 控制器只修改自己管理的记录：latency 层 SetIdentifier 以 `dnslb-` 开头的记录，以及 `pool_domain` 下的记录。
- 已在真实 Route 53 上验证（2026-10-20，`tools/verify-route53.php`，通配符测试域名）：latency Alias 可以指向多值回答记录；日本 / 德国用户分别解析到东京 / 法兰克福；摘除节点、整池切换、多值 ↔ 加权切换的批次顺序均被 Route 53 接受；通配符记录读回后与期望一致。

## 3.1 Cloudflare 记录结构

```
api.example.com   A / AAAA，每个健康节点一条（所有池），proxied 由 dns.proxied 决定
 ├─ A 1.2.3.4  comment="dnslb node web-dallas-a1b2c3"
 └─ A 5.6.7.8  comment="dnslb node web-newark-d4e5f6"
```

- 没有区域层：所有池的节点在同一名称下轮询。代理（橙云）时，Cloudflare 边缘随机选择节点回源，回源失败自动重试同名的其他地址；摘除节点几秒内在边缘生效，不受客户端 DNS 缓存影响。
- 用记录备注标识由 dnslb 管理的记录和节点 ID（相当于 Route 53 的 SetIdentifier），备注前缀之后的节点 ID 必须以 `{service}-` 开头。同一名称下的其他 A / AAAA / CNAME 记录（包括其他服务的）视为外来记录。
- 节点选择与 Route 53 相同，但整池故障时不保留宽限期（池不是 DNS 上的单位，由其他池承接）；所有节点都不健康时全部恢复发布。
- 同步：在 DNS 锁内读取该名称下的记录，按"类型 + 地址"（IPv6 规范形式）比对，生成新增、修改（代理状态、TTL、备注）、删除。Cloudflare 的批量接口在一个事务内按"删除 → 修改 → 新增"执行，但每条记录分别传播到边缘，因此分两次提交：先新增 / 修改，等待 5 秒，再删除，保证传播过程中名称下始终有记录。重复的记录（同一地址多条）删除多余的。
- 外来记录：从未接管时（名称下没有 dnslb 的记录）不发布任何记录，等待 `takeover()`；接管后又出现外来记录时，节点记录照常维护并告警。
- takeover：先添加节点记录，等待传播后删除外来记录（新旧记录同时轮询，解析不中断）；外来记录是 CNAME 或与节点地址相同时，只能在同一批次中先删后增。
- 客户端对 429 / 5xx / 网络错误退避重试（遵守 Retry-After）。重复提交新增只会因"记录已存在"报错，下一次同步按实际记录重新比对。
- 已在真实 Cloudflare 上验证（2026-10-21，`tools/verify-cloudflare.php`，在真实区域上使用通配符测试名称）：通配符代理记录的创建、读回和备注匹配，重复同步无变更；代理记录解析到 Cloudflare 边缘 IP；摘除节点；代理 ↔ 仅 DNS 原地修改，仅 DNS 时解析返回节点地址；接管 A 记录（先加后删）和 CNAME（同一批次先删后增）均被接受。

## 4. 节点生命周期

```
PROVISIONING → BOOTING → WARMING → IN_SERVICE → DRAINING → TERMINATING → TERMINATED
      │           │         │ 超时        │
      │           └─────────┴→ FAILED ─→ TERMINATING
      │                                   │ 持续不健康
      └ 所有 launch 都失败 → 告警          └→ REPLACING（等替补上线）→ TERMINATING
```

- 先写数据库再调用云 API；实例以 node_id 命名并打标签，崩溃后可按标签找回。
- 节点 ID：`{service}-{pool}-{6位随机小写字母数字}`。
- 健康状态与生命周期状态分开存储，v4 / v6 各自独立。节点在 IN_SERVICE（或 REPLACING）且该协议健康时，才有对应的 A / AAAA 记录。
- 整池失败：`pool_failover_after` 时间内保留原有记录；超时后删除该区域 latency 记录。
- 缩容选择：优先缩不健康的节点，其次备选 launch 建出的节点，再其次最新创建的节点；剩下的健康节点不少于 min（有节点故障或正在替换时不排空健康节点）。
- 排空：先确认该节点已不在 DNS 中（DNS 同步失败时一直等待），从确认时起至少等待 `drain.min`；排空期间每个 tick 都复核，记录被外部加回时重新计时（`drain_restarted` 告警），读不到 DNS 时只延后删除；配置了 `drain.idle` 时流量低于阈值即可删除（第四阶段实现）；未配置时等满 `drain.max`。
- 建机失败（所有方式都被拒绝）与预热超时一样计入池的连续失败次数，达到 `max_warmup_failures` 后暂停该池建机，避免反复建机。
- 池节点数超过 max 时，先取消尚未上线的节点（替补和被替换的节点算一台，一起移除），再按缩容顺序排空在线节点；从配置删除的池，在线节点全部排空。
- 云厂商查询失败时不把任何节点视为丢失；新实例 10 分钟内查不到不算丢失（EC2 最终一致性）。
- 删除还没有实例 ID 的节点时按节点 ID 查找；查不到时保持 TERMINATING、每个 tick 再查，进入 TERMINATING 满 10 分钟仍查不到才结束（建机结果未知时实例可能刚建出、暂时查不到）。
- 孤儿实例巡检：tick 每 10 分钟在所有建机区域（以及现有节点所在区域）列出带本服务标签的实例，与状态库比对；连续 15 分钟没有节点引用的实例告警一次（`orphan_instance`），`status()` 中列出。不自动删除（状态库从备份恢复时正常节点也会被当成孤儿），由 `cleanupOrphans()` 预览确认后删除。
- 建机结果未知（网络超时，或重试用尽后仍是限流 / 5xx，之前某次请求可能已经生效）时不切换到下一种方式，下次 tick 先按节点 ID 查找，查不到就用同一方式重发：EC2 用 ClientToken、Linode 用唯一 label 保证同一区域内不重复创建。同一方式结果未知超过 2 分钟、且按节点 ID 仍查不到实例时，才换下一种方式（期间每个 tick 查找并重发一次，至少连续两次查不到，远超 EC2 按标签查询的延迟）。
- EC2 容量不足等错误虽以 5xx 返回，但不重试，立即换下一种建机方式。
- Linode 创建时随机生成 root 密码且不保存，登录依靠 `authorized_keys`。

## 5. 健康检查

- `health()` 每分钟调度一次，进程内每 `interval` 秒一轮，约 55 秒后返回。
- `curl_multi` 并行探测，并发数可配；HTTPS 用 `CURLOPT_RESOLVE` 把域名固定到节点 IP，SNI / Host 正确，可校验证书。
- 判定：状态码范围、可选响应内容、超时；连续 `fall` 次失败摘除，连续 `rise` 次成功加回。
- 保护措施：
  - 金丝雀地址：每轮先探测，全部不通则跳过本轮（探测机自身网络问题）。v4 / v6 分别判断。
  - 全局冻结：超过 `global_freeze_ratio` 的节点同时失败，冻结 DNS 变更并告警。
  - 整池失败延迟切换：见第 4 节。
- 开启 IPv6 时，探测机没有 v6 出口会被每轮的金丝雀检查发现：只丢弃 v6 结果并发出 critical 告警，v4 照常工作（比启动时直接报错退出更稳妥）。`check()` 也会检查 v6 出口。
- 所有池都将被撤下时（例如应用整体故障），全部恢复发布，宁可返回可能故障的节点，也不让域名无法解析。
- 配置中的池都没有可以发布的节点时（例如改名或替换了全部池、所有节点同时丢失），DnsSync 不把对外名称同步成没有记录：保留现有记录并告警，有节点上线后再切换。仍在记录中的旧节点不会开始排空计时，会继续服务到切换完成。
- 事件只在状态切换时记录（金丝雀不可达 / 恢复、冻结 / 解除、节点摘除 / 恢复、整池故障 / 恢复），不会每轮重复。

## 6. 扩缩容

- 按池独立决策。指标查询需按节点返回、带 `node_id` 标签，控制器只统计在线（IN_SERVICE / REPLACING）且还在接流量（至少一个协议族健康）的节点，按 `aggregate`（avg / max）聚合。已从 DNS 摘除的故障节点几乎没有负载，计入会拉低平均值；大面积同时失败时健康检查会冻结，节点仍保持健康，不会因此失去指标。
- 任一指标高于 `scale_out_above` 持续 `sustain` 秒 → 扩容 `step` 台；所有指标低于 `scale_in_below` 持续 `sustain` 秒 → 缩容。
- 缩容前预测：聚合值 × n / (n - step) 不能超过扩容阈值。
- 冷却：`cooldown_out` / `cooldown_in`；启动中的节点计入容量。
- 有数据的在线节点比例低于 `min_coverage` 时不做决策；指标源出错时不做决策。
- 每个池 min / max；min 不足时直接补足。
- 有节点启动中时不扩也不缩（替换中节点的替补不算：替换中的节点和替补合计一台，算作在线）；扩容动作后重新计算持续时间；缩容冷却从上一次任何扩缩容算起。
- 指标查询必须按 `node_id` 聚合：同一节点返回多条序列、或序列缺少 `node_id` 标签时视为查询错误，不做决策。
- 预测缩容后剩余节点负载会超过扩容阈值时少缩或不缩；因此按指标不会缩到 0 台（min 为 0 时也是）。

## 7. 自动修复

- 触发：在线节点 v4 或 v6 持续不健康超过 `unhealthy_for`，或云厂商查不到实例 / 实例已停止。
- 不健康节点：创建替补（节点进入 REPLACING，替补不占 max 名额）→ 替补上线后排空旧节点。替补上线前旧节点恢复健康，则取消替补；替补失败，旧节点恢复为在线，稍后重试。
- 实例丢失的节点：立即撤下，并一对一创建替补。
- 防风暴：健康检查冻结时不替换不健康节点；**所有池**在线节点中不健康比例超过 `max_unhealthy_ratio` 视为系统性故障，只告警不替换（按单个池计算会让只有一台的池永远无法修复）；每小时替换次数上限；同一池连续 `max_warmup_failures` 台新节点未能上线（含建机失败）则暂停该池建机 `pause_for` 秒。

## 7.1 发布新版本（rollout）

- 节点不可变：新机器开机时部署当前版本，发布 = 用新机器替换旧机器。
- `rollout()` 把池中现有节点（PROVISIONING ~ REPLACING）标记为 `outdated`，池记录开始时间、`batch`、总数。tick 在扩缩容之后推进：每个池最多 `batch` 个旧节点处于 REPLACING，从最旧的在线节点开始，复用替换流程（先建替补，上线后排空，`replace_manual` 使旧节点健康时也不取消）。
- 等待：手动暂停、健康检查冻结。停止：池因连续启动失败自动暂停（发布开始时不允许处于自动暂停，因此一定发生在发布期间）→ CRITICAL 告警，清除标记。完成：没有剩余旧节点 → 记录事件，清除标记。
- 发布期间新建的节点不带标记；缩容顺序优先 `outdated` 节点。再次 `rollout()` 重新标记所有现有节点。

## 8. 运行方式

只提供 PHP 调用（`Wcore\Dnslb\Dnslb`），不提供命令行。由调用方的调度器（例如 Laravel scheduler，需 `runInBackground()`）每分钟调用：

- `health()`：进程内每 `interval` 秒一轮，约 55 秒后返回。每轮：探测 → 更新计数 → 冻结 / 整池判定 → DnsSync（有变化时）。结束时发心跳。
- `tick()`：刷新实例状态 → 推进生命周期 → 自动修复 → 补足 min / 削减到 max → 按指标扩缩容 → 推进发布 → DnsSync → 发送告警邮件 → 清理旧数据 → 心跳。

- 两者各自持有文件锁（上一次未结束时 health 最多等 15 秒，tick 最多等 30 秒）；DnsSync 另有一把共用锁，在锁内重新读取数据库计算期望状态，与 DNS 实际记录对比，只提交差异（Route 53 一轮的变更合并为一个原子批次；Cloudflare 见 3.1）。
- 批次内的顺序保证每一步之后都合法：先建 / 改池记录 → 删 latency 记录 → 建 / 改 latency 记录 → 删池记录。新增也用 UPSERT，网络超时后自动重试不会失败。切换 `pool_routing` 时，该池按"摘下 latency → 删旧记录 → 建新记录 → 挂回 latency"处理。
- health 和 tick 会同时修改同一节点 / 池，字段按归属分开写：健康字段只由 health 写，生命周期字段只由 tick 写，互不覆盖。
- 其他方法：`status()`、`check()`、`takeover()`、`scale()`、`drain()`、`replace()`、`rollout()` / `cancelRollout()`、`pause()` / `resume()`、`rebuildState()`，返回结构化结果（数组、`CheckReport`、`SyncResult`、`ScaleResult`、`Node`、`RolloutResult`、`RebuildResult`），展示由调用方负责。参数错误抛 `InvalidArgumentException`，操作无法执行抛 `OperationException`。修改节点的方法持有 tick 锁，不与 tick 并发；`drain()` 和缩容立即同步 DNS。
- 锁和状态库都在本机，只能在一台机器上运行。
- `pause()`：停止该池所有自动增删机器（补足 min、削减到 max、扩缩容、自动修复），健康检查和 DNS 摘除照常；`resume()` 同时解除连续启动失败造成的自动暂停。
- `replace()`：手动替换不受自动修复开关和限频约束，也不会因旧节点健康而取消。
- `rebuildState()`：只补充数据库中缺失的节点；在 DNS 中且运行中的实例恢复为在线，其余按排空处理。
- `$dryRun = true`：使用状态库的内存副本，云厂商写操作和 DNS 变更只记录日志，不修改任何东西。
- 不做交互询问：`takeover(true)` 预览，`takeover()` 直接执行，确认由调用方负责。

## 9. 状态存储（SQLite）

- `nodes`：生命周期状态、云厂商与实例 ID、IP、v4 / v6 健康计数、关键时间点、替补关系、发布标记、最后错误
- `pools`：上次扩容 / 缩容时间、超阈值开始时间、整池失败开始时间、暂停截止时间、连续预热失败次数、发布进度
- `events`：审计日志
- `alerts_sent`：告警限频
- `meta`：全局冻结状态、对外名称保留状态等标记
- `orphans`：孤儿实例巡检的记录（首次发现时间、是否已告警）
- 表结构版本记录在 `PRAGMA user_version`。版本 1 是首次发布时的完整结构；之后只能在末尾追加新版本。tick、health 可能同时首次打开（或升级后首次打开）同一个文件，切换 WAL 和迁移在文件锁（`状态库路径.open.lock`）内进行。
- 文件数据库使用 WAL + `synchronous = FULL`：每次提交都落盘，断电不会丢失已提交的建机记录。
- 演练模式只读打开真实状态库（不迁移、不切换日志模式），复制到内存后照常读写。

## 10. 通知与心跳

- SES 发送邮件；由 tick 每分钟把待通知事件合并为一封。发送 warning / critical 事件，以及已告警问题的恢复通知（整池恢复、冻结解除、探测机网络恢复）。
- 同一告警（类型 + 池 + 节点）在 `throttle` 秒内只发一次；发送失败下次 tick 重试；超过一天未发出的不再发送。
- 事件保留 30 天，已删除的节点记录保留 7 天。
- 心跳为通用 HTTP 请求（URL / 方法 / Basic 认证 / 请求体模板，占位符 `{{service}}`、`{{cmd}}`）。可推送到 Grafana Cloud 的 Influx 行协议接口配合"无数据"告警，或使用 healthchecks.io、Uptime Kuma 等。

## 11. 代码结构

```
src/
├── Dnslb.php           对外入口
├── Application.php     按配置组装组件
├── Config/             配置加载、校验、池级覆盖
├── Operations/         手动操作（状态、检查、扩缩容、替换、发布、重建状态）
├── Http/               基于 curl 的 HTTP 客户端（可替换为假实现）
├── Aws/                SigV4、Route53 / Ec2 / Ses 客户端、限流重试
├── Provider/           ProviderInterface、Ec2Provider、LinodeProvider
├── Metrics/            MetricSourceInterface、PrometheusSource
├── Health/             并行探测、金丝雀、计数与冻结判定
├── Dns/                DnsSync 接口、NodeSelector、Route 53 / Cloudflare 的记录规划与同步
├── Cloudflare/         Cloudflare API 客户端
├── Scaling/            ScalingPolicyInterface、StepPolicy
├── Lifecycle/          建机、状态推进、自动修复、发布、孤儿实例巡检、user-data 渲染
├── State/              SQLite、迁移、仓储
├── Notify/             事件记录、SES 邮件合并限频
├── Heartbeat/          心跳
└── Support/            文件锁、时钟、日志
```

## 12. 从现有记录切换（takeover）

正式域名上已有不由 dnslb 管理的记录、且还没有 dnslb 的 latency 记录时（例如现有的 `*.example.com` A 记录），DnsSync 只同步池记录，不修改这个名称（Cloudflare 的接管方式见 3.1）。接管后又出现外来记录时，latency 记录照常维护并告警。切换流程：

1. 照常运行 tick / health：节点建好、通过健康检查，池记录（第二层）照常发布。池记录与现有记录不冲突，不影响线上。此时第一层的同步被跳过，并给出提示。
2. 调用 `takeover(true)` 预览将要删除的旧记录和将要创建的 latency 记录，确认后调用 `takeover()`，在**同一个原子批次**中完成删除和创建，解析不中断。未开启 IPv6 时旧的 AAAA 记录也会删除：保留的话双栈客户端会优先走 IPv6，继续访问旧服务器。
3. 之后 dnslb 完全接管该名称。

## 13. 实现阶段

1. 基础设施：Http、SigV4 + Route 53 / EC2 / SES 客户端、配置、SQLite
2. 健康检查 + DnsSync
3. 云厂商接入 + 节点生命周期
4. 扩缩容、自动修复、通知、心跳
5. 其余命令、示例、README、最小 IAM 权限策略
