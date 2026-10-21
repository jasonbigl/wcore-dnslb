<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Cloudflare\CloudflareClient;
use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Cloudflare\RecordChange;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\FileLock;
use Wcore\Dnslb\Support\Logger;

/**
 * 把 Cloudflare 上 dns.name 的 A / AAAA 记录同步为 NodeSelector 选出的节点：所有池的节点在同一个名称下轮询，
 * 不区分区域。开启代理（橙云）时，Cloudflare 边缘随机选择节点回源，失败时自动重试其他节点。
 *
 * 由 dnslb 管理的记录用备注 "dnslb node {节点 ID}" 标识。同一名称上已有其他 A / AAAA / CNAME 记录时，
 * 在 takeover 之前不发布任何记录。没有可以发布的节点时保留现有记录（见 EmptyPublishGuard）。
 *
 * 新增 / 修改和删除分两次提交，中间等待传播：Cloudflare 的批量接口在一个事务内先删后增，
 * 但每条记录分别传播到边缘，一起提交时名称下可能短暂没有记录。
 */
final class CloudflareSync implements DnsSync
{
    public const COMMENT_PREFIX = 'dnslb node ';
    /** 新增和删除之间等待传播的秒数 */
    private const PROPAGATION_WAIT = 5;

    private Config $config;
    private CloudflareClient $client;
    private NodeSelector $selector;
    private FileLock $lock;
    private EmptyPublishGuard $guard;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        CloudflareClient $client,
        NodeSelector $selector,
        FileLock $lock,
        EmptyPublishGuard $guard,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->client = $client;
        $this->selector = $selector;
        $this->lock = $lock;
        $this->guard = $guard;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function sync(bool $dryRun = false): SyncResult
    {
        return $this->lock->synchronized(function () use ($dryRun): SyncResult {
            $desired = $this->plan($this->clock->now());
            [$owned, $foreign] = $this->fetchRecords();
            if ($foreign !== [] && $owned === []) {
                // 尚未接管：不往别人的记录里混入节点
                $this->logger->warning(sprintf(
                    '%s has %d record(s) not managed by dnslb; nothing is published there until Dnslb::takeover()',
                    $this->config->dns->name,
                    count($foreign)
                ));
                $result = new SyncResult([], [], $dryRun);
                $result->takeoverPending = true;
                return $result;
            }
            if ($foreign !== []) {
                // 已接管后又出现了其他记录：节点记录照常维护，其他记录也会分到流量
                $this->logger->warning(sprintf(
                    '%s also has %d record(s) not managed by dnslb that receive traffic too; remove them or call Dnslb::takeover()',
                    $this->config->dns->name,
                    count($foreign)
                ));
            }
            $result = $this->guard->hold($desired === [], $owned === [])
                ? new SyncResult([], [], $dryRun)
                : $this->submit(self::diff($desired, $owned), false, $dryRun);
            $result->takeoverPending = $foreign !== [];
            return $result;
        });
    }

    /**
     * 接管对外域名：先添加节点记录，等待传播后再删除原有的 A / AAAA 记录，期间新旧记录一起轮询，解析不中断。
     * 原有记录是 CNAME（不能与 A 记录并存）或与节点地址相同时，只能在同一批次中先删后增，可能有几秒传播不一致。
     */
    public function takeover(bool $dryRun = false): SyncResult
    {
        return $this->lock->synchronized(function () use ($dryRun): SyncResult {
            $name = $this->config->dns->name;
            $desired = $this->plan($this->clock->now());
            if ($desired === []) {
                throw new \RuntimeException("No pool has in-service nodes yet; taking over {$name} now would leave it without records");
            }
            [$owned, $foreign] = $this->fetchRecords();

            $together = false;
            foreach ($foreign as $record) {
                if ($record->type === 'CNAME' || isset($desired[$record->key()])) {
                    $together = true;
                }
            }
            $changes = array_merge(self::diff($desired, $owned), array_map([RecordChange::class, 'delete'], $foreign));
            return $this->submit($changes, $together, $dryRun);
        });
    }

    public function publishedNodeIds(): array
    {
        [$owned] = $this->fetchRecords();
        $ids = [];
        foreach ($owned as $record) {
            $ids[substr((string) $record->comment, strlen(self::COMMENT_PREFIX))] = true;
        }
        return $ids;
    }

    public function desiredFingerprint(int $now): string
    {
        return sha1((string) json_encode(array_map(static function (DnsRecord $record): array {
            return $record->toApi();
        }, $this->plan($now))));
    }

    public function check(): array
    {
        $dns = $this->config->dns;
        $items = [];
        try {
            $zone = $this->client->getZone($dns->zoneId);
            $name = ltrim($dns->name, '*.');
            if ($name !== $zone['name'] && substr($name, -strlen('.' . $zone['name'])) !== '.' . $zone['name']) {
                throw new \RuntimeException("{$dns->name} 不在区域 {$zone['name']} 内");
            }
            if ($zone['status'] === 'active') {
                $items[] = new CheckItem(CheckItem::OK, 'Cloudflare 区域', "{$zone['name']}（{$zone['id']}）");
            } else {
                $items[] = new CheckItem(CheckItem::WARN, 'Cloudflare 区域', "{$zone['name']} 的状态为 {$zone['status']}：域名的 NS 还没有切换到 Cloudflare，记录暂不生效");
            }
        } catch (\Throwable $e) {
            $items[] = new CheckItem(CheckItem::FAIL, 'Cloudflare 区域', $e->getMessage());
        }

        try {
            [$owned, $foreign] = $this->fetchRecords();
        } catch (\Throwable $e) {
            $items[] = new CheckItem(CheckItem::FAIL, 'Cloudflare 现有记录', $e->getMessage());
            return $items;
        }
        $items[] = new CheckItem(CheckItem::OK, 'Cloudflare 现有记录', count($owned) . ' 条由 dnslb 管理（' . ($dns->proxied ? '代理' : '仅 DNS') . '）');
        if ($foreign !== []) {
            $list = implode('、', array_map(static function (DnsRecord $record): string {
                return "{$record->type} {$record->content}" . ($record->proxied ? '（代理）' : '');
            }, $foreign));
            $items[] = new CheckItem(CheckItem::WARN, '待接管的记录', "{$dns->name} 上有 " . count($foreign) . " 条不由 dnslb 管理的记录（{$list}），节点上线后用 Dnslb::takeover() 无中断切换");
        }
        return $items;
    }

    /**
     * 期望的记录。
     *
     * @return array<string,DnsRecord> 以 DnsRecord::key() 为键
     */
    public function plan(int $now): array
    {
        $dns = $this->config->dns;
        $records = [];
        foreach ($this->selector->select($now) as $family => $pools) {
            foreach ($pools as $nodes) {
                foreach ($nodes as $node) {
                    $record = new DnsRecord(
                        NodeSelector::recordType($family),
                        $dns->name,
                        (string) $node->ip($family),
                        $dns->proxied,
                        $dns->proxied ? DnsRecord::TTL_AUTO : $dns->ttl,
                        self::COMMENT_PREFIX . $node->nodeId
                    );
                    $records[$record->key()] = $record;
                }
            }
        }
        ksort($records);
        return $records;
    }

    /**
     * 读取 dns.name 下由本服务管理的记录，以及不由 dnslb 管理、接管时需要替换的 A / AAAA / CNAME 记录。
     *
     * @return array{0: DnsRecord[], 1: DnsRecord[]}
     */
    public function fetchRecords(): array
    {
        $ownedPrefix = self::COMMENT_PREFIX . $this->config->service . '-';
        $owned = [];
        $foreign = [];
        foreach ($this->client->listRecords($this->config->dns->zoneId, $this->config->dns->name) as $record) {
            if (!in_array($record->type, ['A', 'AAAA', 'CNAME'], true)) {
                continue;
            }
            if ($record->type !== 'CNAME' && $record->comment !== null && strpos($record->comment, $ownedPrefix) === 0) {
                $owned[] = $record;
            } else {
                $foreign[] = $record;
            }
        }
        return [$owned, $foreign];
    }

    /**
     * 从现有记录到期望记录的变更：新增、修改（代理状态、TTL、备注）、删除（含重复的记录）。
     *
     * @param array<string,DnsRecord> $desired
     * @param DnsRecord[] $owned
     * @return RecordChange[]
     */
    public static function diff(array $desired, array $owned): array
    {
        $creates = [];
        $updates = [];
        $deletes = [];
        $actual = [];
        foreach ($owned as $record) {
            $key = $record->key();
            if (!isset($desired[$key]) || isset($actual[$key])) {
                $deletes[] = RecordChange::delete($record);
            } else {
                $actual[$key] = $record;
            }
        }
        foreach ($desired as $key => $record) {
            $current = $actual[$key] ?? null;
            if ($current === null) {
                $creates[] = RecordChange::create($record);
            } elseif (!$record->sameAs($current)) {
                $updates[] = RecordChange::update($current, $record);
            }
        }
        return array_merge($creates, $updates, $deletes);
    }

    /**
     * @param RecordChange[] $changes
     * @param bool $together 在同一批次中提交（Cloudflare 先删后增）
     */
    private function submit(array $changes, bool $together, bool $dryRun): SyncResult
    {
        if ($changes === []) {
            $this->logger->debug('DNS is up to date');
            return new SyncResult([], [], $dryRun);
        }
        $writes = [];
        $deletes = [];
        foreach ($changes as $change) {
            if ($change->action === RecordChange::DELETE) {
                $deletes[] = $change;
            } else {
                $writes[] = $change;
            }
        }
        // 按实际执行顺序排列
        $changes = $together ? array_merge($deletes, $writes) : array_merge($writes, $deletes);
        foreach ($changes as $change) {
            $this->logger->info(($dryRun ? '[dry-run] ' : '') . 'DNS ' . $change->describe());
        }
        if ($dryRun) {
            return new SyncResult($changes, [], true);
        }

        if ($together || $writes === [] || $deletes === []) {
            $this->apply($writes, $deletes);
        } else {
            $this->apply($writes, []);
            $this->clock->sleep(self::PROPAGATION_WAIT * 1000000);
            $this->apply([], $deletes);
        }
        $this->logger->info('DNS changes submitted', ['changes' => count($changes)]);
        return new SyncResult($changes, [], false);
    }

    /**
     * @param RecordChange[] $writes
     * @param RecordChange[] $deletes
     */
    private function apply(array $writes, array $deletes): void
    {
        $patches = [];
        $posts = [];
        foreach ($writes as $change) {
            if ($change->action === RecordChange::UPDATE) {
                $patches[] = $change->record;
            } else {
                $posts[] = $change->record;
            }
        }
        $this->client->batch($this->config->dns->zoneId, array_map(static function (RecordChange $change): DnsRecord {
            return $change->record;
        }, $deletes), $patches, $posts);
    }
}
