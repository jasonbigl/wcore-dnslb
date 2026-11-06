<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\Support\Logger;

/**
 * 把同一批节点同步到所有配置的区域（dns.zones）：每个区域一个 Route53Sync / CloudflareSync，按配置顺序依次同步。
 *
 * - 各区域互不影响：一个区域失败时其他区域照常同步，最后再抛出异常，调用方下一轮重试；
 * - 节点只要还在任何一个区域的记录中就算"在 DNS 中"（排空确认摘除后才删除机器），任何一个区域读不到记录时抛出异常；
 * - 等待接管、外来记录、保留记录（dns_empty_held）都按区域分别处理。
 *
 * 只有一个区域时行为与直接使用该区域的同步完全相同（异常原样抛出）。
 */
final class MultiZoneSync implements DnsSync
{
    /** @var array<string,DnsSync> 对外名称 => 该区域的同步 */
    private array $zones;
    private Logger $logger;

    /**
     * @param array<string,DnsSync> $zones 对外名称 => 该区域的同步，至少一个
     */
    public function __construct(array $zones, Logger $logger)
    {
        if ($zones === []) {
            throw new \InvalidArgumentException('At least one DNS zone is required');
        }
        $this->zones = $zones;
        $this->logger = $logger;
    }

    /**
     * @return array<string,DnsSync> 对外名称 => 该区域的同步
     */
    public function zones(): array
    {
        return $this->zones;
    }

    /**
     * @throws \InvalidArgumentException 没有这个名称的区域
     */
    public function zone(string $name): DnsSync
    {
        $key = strtolower(rtrim($name, '.'));
        if (!isset($this->zones[$key])) {
            throw new \InvalidArgumentException("Unknown DNS name \"{$name}\" (configured: " . implode(', ', array_keys($this->zones)) . ')');
        }
        return $this->zones[$key];
    }

    public function sync(bool $dryRun = false): SyncResult
    {
        return $this->each($this->zones, $dryRun, static function (DnsSync $sync) use ($dryRun): SyncResult {
            return $sync->sync($dryRun);
        });
    }

    /**
     * 接管所有区域（没有外来记录的区域只做普通同步），或只接管 $name 这一个区域。
     *
     * @throws \RuntimeException 没有可发布的节点时拒绝接管
     * @throws \InvalidArgumentException 没有这个名称的区域
     */
    public function takeover(bool $dryRun = false, ?string $name = null): SyncResult
    {
        $zones = $name === null ? $this->zones : [strtolower(rtrim($name, '.')) => $this->zone($name)];
        return $this->each($zones, $dryRun, static function (DnsSync $sync) use ($dryRun): SyncResult {
            return $sync->takeover($dryRun);
        });
    }

    public function publishedNodeIds(): array
    {
        $ids = [];
        foreach ($this->zones as $sync) {
            $ids += $sync->publishedNodeIds();
        }
        ksort($ids);
        return $ids;
    }

    public function desiredFingerprint(int $now): string
    {
        if (count($this->zones) === 1) {
            return reset($this->zones)->desiredFingerprint($now);
        }
        $parts = [];
        foreach ($this->zones as $name => $sync) {
            $parts[] = $name . '=' . $sync->desiredFingerprint($now);
        }
        return sha1(implode("\n", $parts));
    }

    public function check(): array
    {
        $items = [];
        foreach ($this->zones as $name => $sync) {
            foreach ($sync->check() as $item) {
                if (count($this->zones) > 1) {
                    // 多个区域时检查项名称带上对外名称，便于区分
                    $item->name .= "（{$name}）";
                }
                $items[] = $item;
            }
        }
        return $items;
    }

    /**
     * 依次处理各区域并合并结果；有区域失败时，处理完其余区域后抛出异常。
     *
     * @param array<string,DnsSync> $zones
     * @param callable(DnsSync): SyncResult $action
     */
    private function each(array $zones, bool $dryRun, callable $action): SyncResult
    {
        if (count($this->zones) === 1) {
            return $action(reset($zones));
        }

        $results = [];
        $failures = [];
        foreach ($zones as $name => $sync) {
            try {
                $results[] = $action($sync);
            } catch (\Throwable $e) {
                $failures[$name] = $e;
                $this->logger->warning("DNS sync of {$name} failed, continuing with the other zones: {$e->getMessage()}");
            }
        }
        if ($failures !== []) {
            $messages = [];
            foreach ($failures as $name => $e) {
                $messages[] = "{$name}: {$e->getMessage()}";
            }
            throw new \RuntimeException(implode('; ', $messages), 0, reset($failures));
        }

        $changes = [];
        $changeIds = [];
        $pending = false;
        foreach ($results as $result) {
            $changes = array_merge($changes, $result->changes);
            $changeIds = array_merge($changeIds, $result->changeIds);
            $pending = $pending || $result->takeoverPending;
        }
        $merged = new SyncResult($changes, $changeIds, $dryRun);
        $merged->takeoverPending = $pending;
        return $merged;
    }
}
