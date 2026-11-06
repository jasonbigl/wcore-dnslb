<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Aws\Route53\AliasTarget;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\DnsConfig;
use Wcore\Dnslb\Config\DnsZone;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\State\Node;

/**
 * 计算一个区域在 Route 53 上应有的记录：NodeSelector 选出的节点发布到各池记录名下（第二层），
 * 池内有记录时，才发布该区域的 latency Alias 记录（第一层）。
 * Alias 只能指向同一托管区域内的记录，所以每个区域都有自己的池记录。
 */
final class Route53Planner
{
    public const LATENCY_ID_PREFIX = 'dnslb-';

    private Config $config;
    private DnsZone $zone;
    private NodeSelector $selector;

    public function __construct(Config $config, DnsZone $zone, NodeSelector $selector)
    {
        $this->config = $config;
        $this->zone = $zone;
        $this->selector = $selector;
    }

    /**
     * @return array<string,RecordSet> 以 RecordSet::key() 为键
     */
    public function plan(int $now): array
    {
        $records = [];
        foreach ($this->selector->select($now) as $family => $pools) {
            foreach ($pools as $name => $nodes) {
                $pool = $this->config->pools[$name];
                foreach ($nodes as $node) {
                    $record = $this->nodeRecord($pool, $node, $family);
                    $records[$record->key()] = $record;
                }
                $latency = $this->latencyRecord($pool, $family);
                $records[$latency->key()] = $latency;
            }
        }

        ksort($records);
        return $records;
    }

    /**
     * 期望记录集合的指纹，用于判断是否需要同步。
     *
     * @param array<string,RecordSet> $records
     */
    public static function fingerprint(array $records): string
    {
        ksort($records);
        $xml = '';
        foreach ($records as $record) {
            $xml .= $record->toXml();
        }
        return sha1($xml);
    }

    private function nodeRecord(PoolConfig $pool, Node $node, string $family): RecordSet
    {
        $dns = $this->config->dns;
        $name = $this->zone->poolRecordName($pool->name);
        $type = NodeSelector::recordType($family);
        $ip = (string) $node->ip($family);
        if ($dns->poolRouting === DnsConfig::ROUTING_WEIGHTED) {
            return RecordSet::weighted($name, $type, $node->nodeId, $node->weight, $dns->ttl, [$ip]);
        }
        return RecordSet::multiValue($name, $type, $node->nodeId, $dns->ttl, [$ip]);
    }

    private function latencyRecord(PoolConfig $pool, string $family): RecordSet
    {
        return RecordSet::latencyAlias(
            $this->zone->name,
            NodeSelector::recordType($family),
            self::LATENCY_ID_PREFIX . $pool->name,
            (string) $pool->latencyRegion,
            new AliasTarget($this->zone->zoneId, $this->zone->poolRecordName($pool->name), true)
        );
    }
}
