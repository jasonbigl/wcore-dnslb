<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Aws\Route53\Route53Client;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\DnsZone;
use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * 把一个区域在 Route 53 上的记录同步到 Route53Planner 计算出的期望状态（按延迟就近选择池，池内多值 / 加权）。
 * 多个区域时每个区域一个实例，由 MultiZoneSync 依次同步。
 *
 * 在锁内重新读取数据库计算期望记录，再读取 Route 53 实际记录，只提交差异。没有可以发布的节点时保留现有记录（见 EmptyPublishGuard），
 * 接管后对外域名上又出现其他记录时告警（见 ForeignRecordTracker）。
 *
 * 只管理以下记录：
 * - 对外名称下 SetIdentifier 以 "dnslb-" 开头的 latency Alias 记录；
 * - 各池记录名下 SetIdentifier 以 "{service}-" 开头的 A / AAAA 记录（即节点 ID）。
 */
final class Route53Sync implements DnsSync
{
    /** 单批变更上限，超过时按依赖顺序分批提交 */
    private const MAX_CHANGES_PER_BATCH = 300;
    /** 一次列出池记录所在子树时最多翻几页；更大时改为逐个池记录名查询 */
    private const MAX_POOL_SUBTREE_PAGES = 3;

    private Config $config;
    private DnsZone $zone;
    private Route53Client $route53;
    private Route53Planner $planner;
    private FileLock $lock;
    private EmptyPublishGuard $guard;
    private ForeignRecordTracker $foreign;
    private PoolWithdrawalTracker $withdrawals;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        DnsZone $zone,
        Route53Client $route53,
        Route53Planner $planner,
        FileLock $lock,
        EmptyPublishGuard $guard,
        ForeignRecordTracker $foreign,
        PoolWithdrawalTracker $withdrawals,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->zone = $zone;
        $this->route53 = $route53;
        $this->planner = $planner;
        $this->lock = $lock;
        $this->guard = $guard;
        $this->foreign = $foreign;
        $this->withdrawals = $withdrawals;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function sync(bool $dryRun = false): SyncResult
    {
        return $this->lock->synchronized(function () use ($dryRun): SyncResult {
            $name = $this->zone->name;
            $desired = $this->planner->plan($this->clock->now());
            [$actual, $foreign] = $this->fetchRecords();

            $latencyPublished = false;
            foreach ($actual as $record) {
                if ($record->name === $name) {
                    $latencyPublished = true;
                    break;
                }
            }
            // 接管后又出现其他记录时告警（latency 记录照常维护，其他记录也会分到流量），消失后记录恢复
            $this->foreign->observe(self::describeAll($foreign), $latencyPublished);
            if ($foreign !== [] && !$latencyPublished) {
                // 尚未接管：对外域名上还有其他记录，只同步池记录，latency 记录等待 takeover
                $desired = array_filter($desired, static function (RecordSet $r) use ($name): bool {
                    return $r->name !== $name;
                });
                $this->logger->warning(sprintf(
                    '%s has %d record(s) not managed by dnslb; only pool records are synced. Call Dnslb::takeover() to switch over',
                    $name,
                    count($foreign)
                ));
            }

            $publishing = false;
            foreach ($desired as $record) {
                if ($record->name === $name) {
                    $publishing = true;
                    break;
                }
            }
            $result = $this->guard->hold(!$publishing, !$latencyPublished)
                ? new SyncResult([], [], $dryRun)
                : $this->submit(self::diff($desired, $actual, $name), $dryRun);
            $result->takeoverPending = $foreign !== [];
            return $result;
        });
    }

    /**
     * 接管对外域名：在同一个原子批次中删除该名称上不由 dnslb 管理的 A / AAAA / CNAME 记录，
     * 并创建 latency 记录，解析不中断。$dryRun 为 true 时只返回将要执行的变更。
     *
     * @throws \RuntimeException 没有可发布的池时拒绝接管
     */
    public function takeover(bool $dryRun = false): SyncResult
    {
        return $this->lock->synchronized(function () use ($dryRun): SyncResult {
            $name = $this->zone->name;
            $desired = $this->planner->plan($this->clock->now());
            [$actual, $foreign] = $this->fetchRecords();

            $latency = array_filter($desired, static function (RecordSet $r) use ($name): bool {
                return $r->name === $name;
            });
            if ($latency === []) {
                throw new \RuntimeException("No pool has in-service nodes yet; taking over {$name} now would leave it without records");
            }

            $changes = self::diff($desired, $actual, $name);
            // 旧记录必须在 latency 记录创建之前删除（同名同类型不能混用路由策略），但在池记录创建之后
            $firstLatency = count($changes);
            foreach ($changes as $i => $change) {
                if ($change->recordSet->name === $name && $change->action !== Change::DELETE) {
                    $firstLatency = $i;
                    break;
                }
            }
            $deletes = array_map([Change::class, 'delete'], $foreign);
            $changes = array_merge(array_slice($changes, 0, $firstLatency), $deletes, array_slice($changes, $firstLatency));

            $result = $this->submit($changes, $dryRun);
            if (!$dryRun && $foreign !== []) {
                $this->foreign->recordTakeover(self::describeAll($foreign));
            }
            return $result;
        });
    }

    public function publishedNodeIds(): array
    {
        [$owned] = $this->fetchRecords();
        $ids = [];
        foreach ($owned as $record) {
            if ($record->aliasTarget === null && $record->setIdentifier !== null) {
                $ids[$record->setIdentifier] = true;
            }
        }
        return $ids;
    }

    public function desiredFingerprint(int $now): string
    {
        return Route53Planner::fingerprint($this->planner->plan($now));
    }

    public function check(): array
    {
        $dns = $this->zone;
        $items = [];
        try {
            $zone = $this->route53->getHostedZone($dns->zoneId);
            $name = ltrim($dns->name, '*.');
            if ($name !== $zone['name'] && substr($name, -strlen('.' . $zone['name'])) !== '.' . $zone['name']) {
                throw new \RuntimeException("{$dns->name} 不在托管区域 {$zone['name']} 内");
            }
            $items[] = new CheckItem(CheckItem::OK, 'Route 53 托管区域', "{$zone['id']}（{$zone['name']}）");
        } catch (\Throwable $e) {
            $items[] = new CheckItem(CheckItem::FAIL, 'Route 53 托管区域', $e->getMessage());
        }

        try {
            [$owned, $foreign] = $this->fetchRecords();
        } catch (\Throwable $e) {
            $items[] = new CheckItem(CheckItem::FAIL, 'Route 53 现有记录', $e->getMessage());
            return $items;
        }
        $items[] = new CheckItem(CheckItem::OK, 'Route 53 现有记录', count($owned) . ' 条由 dnslb 管理');
        if ($foreign !== []) {
            $list = implode('、', self::describeAll($foreign));
            $items[] = new CheckItem(CheckItem::WARN, '待接管的记录', "{$dns->name} 上有 " . count($foreign) . " 条不由 dnslb 管理的记录（{$list}），节点上线后用 Dnslb::takeover() 无中断切换");
        }
        return $items;
    }

    /**
     * @param Change[] $changes
     */
    private function submit(array $changes, bool $dryRun): SyncResult
    {
        if ($changes === []) {
            $this->logger->debug('DNS is up to date');
            return new SyncResult([], [], $dryRun);
        }
        foreach ($changes as $change) {
            $this->logger->info(($dryRun ? '[dry-run] ' : '') . 'DNS ' . $change->describe());
        }
        if ($dryRun) {
            return new SyncResult($changes, [], true);
        }

        $ids = [];
        foreach (array_chunk($changes, self::MAX_CHANGES_PER_BATCH) as $batch) {
            $ids[] = $this->route53->changeRecordSets($this->zone->zoneId, $batch, "dnslb {$this->config->service}");
        }
        $this->logger->info('DNS changes submitted', ['name' => $this->zone->name, 'changes' => count($changes), 'change_ids' => $ids]);
        $this->withdrawals->submitted($changes);
        return new SyncResult($changes, $ids, false);
    }

    /**
     * 读取 Route 53 上由本服务管理的记录。对外域名上有其他记录时抛出异常（见 fetchRecords()）。
     *
     * @return array<string,RecordSet>
     * @throws DnsConflictException
     */
    public function fetchActual(): array
    {
        [$owned, $foreign] = $this->fetchRecords();
        if ($foreign !== []) {
            $record = $foreign[0];
            throw new DnsConflictException(
                "{$record->name} already has a {$record->type} record not managed by dnslb"
                . ($record->setIdentifier !== null ? " (SetIdentifier {$record->setIdentifier})" : '')
                . '; use Dnslb::takeover() to replace it without downtime'
            );
        }
        return $owned;
    }

    /**
     * 读取由本服务管理的记录，以及对外域名上不由 dnslb 管理、接管时需要替换的 A / AAAA / CNAME 记录。
     *
     * @return array{0: array<string,RecordSet>, 1: RecordSet[]}
     * @throws DnsConflictException 池记录名下有其他记录（池记录名必须专用于 dnslb）
     */
    public function fetchRecords(): array
    {
        $dns = $this->zone;
        $owned = [];
        $foreign = [];

        $poolNames = [];
        foreach ($this->config->pools as $pool) {
            $poolNames[$dns->poolRecordName($pool->name)] = true;
        }
        // 已从配置删除的池、或 pool_domain 改过之后的旧记录名，通过现有 latency 记录找回
        $legacyNames = [];

        foreach ($this->route53->listRecordSetsByName($dns->zoneId, $dns->name) as $record) {
            if (self::isOwnedLatency($record)) {
                $owned[$record->key()] = $record;
                $target = $record->aliasTarget->dnsName;
                if (!isset($poolNames[$target])) {
                    $legacyNames[$target] = true;
                }
            } elseif (self::isAddressType($record) || $record->type === 'CNAME') {
                $foreign[] = $record;
            }
        }

        // 池记录名都在同一个后缀下（pool_domain 去掉 "{pool}."），一次列出这棵子树（通常 1 页）代替逐个名称查询：
        // 每个区域每次同步固定 2 次读取，不随池数增加。子树太大时（例如 pool_domain 直接放在区域根下）退回逐个查询
        $suffix = substr($dns->poolDomain, strlen('{pool}.'));
        $subtree = $this->route53->listRecordSetsUnder($dns->zoneId, $suffix, self::MAX_POOL_SUBTREE_PAGES);
        $subtreeByName = [];
        foreach ($subtree ?? [] as $record) {
            $subtreeByName[$record->name][] = $record;
        }

        foreach (array_keys($poolNames + $legacyNames) as $name) {
            $isConfigured = isset($poolNames[$name]);
            $records = $subtree !== null && Route53Client::isWithin($name, $suffix)
                ? $subtreeByName[$name] ?? []
                : $this->route53->listRecordSetsByName($dns->zoneId, $name);
            foreach ($records as $record) {
                if (!self::isAddressType($record) && $record->type !== 'CNAME') {
                    continue;
                }
                if ($this->isOwnedNodeRecord($record)) {
                    $owned[$record->key()] = $record;
                } elseif ($isConfigured) {
                    throw new DnsConflictException(
                        "{$record->name} already has a {$record->type} record not managed by dnslb; "
                        . 'pool record names must be dedicated to dnslb (see pool_domain)'
                    );
                }
            }
        }

        ksort($owned);
        return [$owned, $foreign];
    }

    /**
     * 计算从 $actual 到 $desired 的变更，并按依赖顺序排列，使批次中每一步之后的状态都合法：
     * 先建 / 改池记录，再删 latency 记录，再建 / 改 latency 记录，最后删池记录，
     * 保证 latency Alias 任何时刻都指向存在的记录。
     *
     * 新增也使用 UPSERT：提交请求超时但实际已生效时，自动重试不会因"记录已存在"失败。
     *
     * 切换 pool_routing（多值 ↔ 加权）时，同名同类型的记录不能混用路由策略，
     * 该池按"摘下 latency → 删全部旧记录 → 建新记录 → 挂回 latency"的顺序处理。
     *
     * @param array<string,RecordSet> $desired
     * @param array<string,RecordSet> $actual
     * @return Change[]
     */
    public static function diff(array $desired, array $actual, string $latencyName): array
    {
        $phases = [
            'latency_detach' => [],
            'pool_remove_old' => [],
            'pool_upsert' => [],
            'latency_delete' => [],
            'latency_upsert' => [],
            'pool_delete' => [],
        ];

        // 找出路由策略发生变化的池记录（名称 + 类型）
        $desiredPolicy = [];
        foreach ($desired as $record) {
            if ($record->name !== $latencyName) {
                $desiredPolicy[$record->name . '|' . $record->type] = self::routingPolicy($record);
            }
        }
        $switched = [];
        foreach ($actual as $record) {
            $group = $record->name . '|' . $record->type;
            if ($record->name !== $latencyName && isset($desiredPolicy[$group]) && $desiredPolicy[$group] !== self::routingPolicy($record)) {
                $switched[$group] = true;
            }
        }
        $isSwitched = static function (RecordSet $record) use ($switched, $latencyName): bool {
            $group = $record->name === $latencyName && $record->aliasTarget !== null
                ? $record->aliasTarget->dnsName . '|' . $record->type
                : $record->name . '|' . $record->type;
            return isset($switched[$group]);
        };

        foreach ($actual as $key => $record) {
            if ($isSwitched($record)) {
                $phases[$record->name === $latencyName ? 'latency_detach' : 'pool_remove_old'][] = Change::delete($record);
            } elseif (!isset($desired[$key])) {
                $phases[$record->name === $latencyName ? 'latency_delete' : 'pool_delete'][] = Change::delete($record);
            }
        }
        foreach ($desired as $key => $record) {
            $current = $actual[$key] ?? null;
            if ($current !== null && !$isSwitched($record) && $record->sameAs($current)) {
                continue;
            }
            $phases[$record->name === $latencyName ? 'latency_upsert' : 'pool_upsert'][] = Change::upsert($record);
        }

        return array_merge(...array_values($phases));
    }

    /**
     * 外来记录的描述，例如 "A 203.0.113.9"、"CNAME -> old.example.net"。
     *
     * @param RecordSet[] $records
     * @return string[]
     */
    private static function describeAll(array $records): array
    {
        return array_map(static function (RecordSet $record): string {
            return $record->type . ' ' . ($record->aliasTarget !== null ? '-> ' . $record->aliasTarget->dnsName : implode(',', $record->values));
        }, $records);
    }

    private static function isAddressType(RecordSet $record): bool
    {
        return $record->type === 'A' || $record->type === 'AAAA';
    }

    private static function isOwnedLatency(RecordSet $record): bool
    {
        return self::isAddressType($record)
            && $record->setIdentifier !== null
            && strpos($record->setIdentifier, Route53Planner::LATENCY_ID_PREFIX) === 0
            && $record->region !== null
            && $record->aliasTarget !== null;
    }

    private function isOwnedNodeRecord(RecordSet $record): bool
    {
        return $record->setIdentifier !== null
            && strpos($record->setIdentifier, $this->config->service . '-') === 0
            && $record->aliasTarget === null
            && $record->region === null;
    }

    private static function routingPolicy(RecordSet $record): string
    {
        if ($record->multiValueAnswer) {
            return 'multivalue';
        }
        return $record->weight !== null ? 'weighted' : 'other';
    }
}
