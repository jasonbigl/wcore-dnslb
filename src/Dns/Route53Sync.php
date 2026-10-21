<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Aws\Route53\Route53Client;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * 把 Route 53 同步到 Route53Planner 计算出的期望状态（按延迟就近选择池，池内多值 / 加权）。
 *
 * 在锁内重新读取数据库计算期望记录，再读取 Route 53 实际记录，只提交差异。没有可以发布的节点时保留现有记录（见 EmptyPublishGuard）。
 *
 * 只管理以下记录：
 * - dns.name 下 SetIdentifier 以 "dnslb-" 开头的 latency Alias 记录；
 * - 各池记录名下 SetIdentifier 以 "{service}-" 开头的 A / AAAA 记录（即节点 ID）。
 */
final class Route53Sync implements DnsSync
{
    /** 单批变更上限，超过时按依赖顺序分批提交 */
    private const MAX_CHANGES_PER_BATCH = 300;

    private Config $config;
    private Route53Client $route53;
    private Route53Planner $planner;
    private FileLock $lock;
    private EmptyPublishGuard $guard;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        Route53Client $route53,
        Route53Planner $planner,
        FileLock $lock,
        EmptyPublishGuard $guard,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->route53 = $route53;
        $this->planner = $planner;
        $this->lock = $lock;
        $this->guard = $guard;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function sync(bool $dryRun = false): SyncResult
    {
        return $this->lock->synchronized(function () use ($dryRun): SyncResult {
            $name = $this->config->dns->name;
            $desired = $this->planner->plan($this->clock->now());
            [$actual, $foreign] = $this->fetchRecords();

            $latencyPublished = false;
            foreach ($actual as $record) {
                if ($record->name === $name) {
                    $latencyPublished = true;
                    break;
                }
            }
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
            } elseif ($foreign !== []) {
                // 已接管后又出现了其他记录：latency 记录照常维护，其他记录也会分到流量
                $this->logger->warning(sprintf(
                    '%s also has %d record(s) not managed by dnslb that receive traffic too; remove them or call Dnslb::takeover()',
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
            $name = $this->config->dns->name;
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

            return $this->submit($changes, $dryRun);
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
        $dns = $this->config->dns;
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
            $list = implode('、', array_map(static function (RecordSet $record): string {
                return $record->type . ' ' . ($record->aliasTarget !== null ? '-> ' . $record->aliasTarget->dnsName : implode(',', $record->values));
            }, $foreign));
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
            $ids[] = $this->route53->changeRecordSets($this->config->dns->zoneId, $batch, "dnslb {$this->config->service}");
        }
        $this->logger->info('DNS changes submitted', ['changes' => count($changes), 'change_ids' => $ids]);
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
        $dns = $this->config->dns;
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

        foreach (array_keys($poolNames + $legacyNames) as $name) {
            $isConfigured = isset($poolNames[$name]);
            foreach ($this->route53->listRecordSetsByName($dns->zoneId, $name) as $record) {
                if (!self::isAddressType($record) && $record->type !== 'CNAME') {
                    continue;
                }
                if ($this->isOwnedNodeRecord($record)) {
                    $owned[$record->key()] = $record;
                } elseif ($isConfigured) {
                    throw new DnsConflictException(
                        "{$record->name} already has a {$record->type} record not managed by dnslb; "
                        . 'pool record names must be dedicated to dnslb (see dns.pool_domain)'
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
