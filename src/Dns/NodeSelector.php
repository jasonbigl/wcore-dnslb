<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\State\PoolState;

/**
 * 根据数据库中的节点状态，选出每个协议族应当发布到 DNS 的节点（Route 53 和 Cloudflare 共用）。
 *
 * 规则（每个协议族分别计算）：
 * - 池内有健康的在线节点：只发布健康节点。
 * - 池内在线节点全部不健康：
 *   - Route 53：在 pool_failover_after 时间内保留最后一批节点不动，超时后撤下整个池（删除该区域的
 *     latency 记录，流量转到次近区域），避免探测线路的短暂问题造成跨区域切换；
 *   - Cloudflare：所有池在同一个名称下轮询，池不是 DNS 上的单位，直接撤下，由其他池承接。
 * - 如果按上述规则所有池都将被撤下：全部恢复发布（宁可返回可能故障的节点，也不能让域名没有记录）。
 */
final class NodeSelector
{
    private const SERVING_STATES = [Node::IN_SERVICE, Node::REPLACING];

    private Config $config;
    private NodeRepository $nodes;
    private PoolRepository $pools;

    public function __construct(Config $config, NodeRepository $nodes, PoolRepository $pools)
    {
        $this->config = $config;
        $this->nodes = $nodes;
        $this->pools = $pools;
    }

    /**
     * @return array<string,array<string,Node[]>> 协议族 => 池名（按配置顺序，只含要发布的池）=> 节点
     */
    public function select(int $now): array
    {
        $byPool = [];
        foreach ($this->nodes->byStates(self::SERVING_STATES) as $node) {
            if (isset($this->config->pools[$node->pool])) {
                $byPool[$node->pool][] = $node;
            }
        }
        $poolStates = $this->pools->all();

        $result = [];
        foreach ($this->families() as $family) {
            $selected = [];
            $candidatesByPool = [];
            foreach (array_keys($this->config->pools) as $name) {
                $candidates = array_values(array_filter($byPool[$name] ?? [], static function (Node $node) use ($family): bool {
                    return $node->ip($family) !== null;
                }));
                $candidatesByPool[$name] = $candidates;
                $selected[$name] = $this->selectInPool($candidates, $family, $poolStates[$name] ?? null, $now);
            }

            if (array_filter($selected) === [] && array_filter($candidatesByPool) !== []) {
                $selected = $candidatesByPool;
            }
            $result[$family] = array_filter($selected);
        }
        return $result;
    }

    /**
     * @return string[]
     */
    public function families(): array
    {
        return $this->config->dns->ipv6 ? [Node::FAMILY_V4, Node::FAMILY_V6] : [Node::FAMILY_V4];
    }

    public static function recordType(string $family): string
    {
        return $family === Node::FAMILY_V6 ? 'AAAA' : 'A';
    }

    /**
     * @param Node[] $candidates 池内有该协议族地址的在线节点
     * @return Node[]
     */
    private function selectInPool(array $candidates, string $family, ?PoolState $state, int $now): array
    {
        if ($candidates === []) {
            return [];
        }
        $healthy = array_values(array_filter($candidates, static function (Node $node) use ($family): bool {
            return $node->health($family)->healthy;
        }));
        if ($healthy !== [] || $this->config->dns->isCloudflare()) {
            return $healthy;
        }

        $downSince = $state === null ? null : $state->downSince($family);
        if ($downSince === null) {
            // health 进程尚未确认整池故障，先保持全部发布
            return $candidates;
        }
        if ($now - $downSince >= $this->config->healthCheck->poolFailoverAfter) {
            return [];
        }
        // 宽限期内：保留在整池故障时刻才变为不健康的节点，即故障前仍在 DNS 中的那批
        $last = array_values(array_filter($candidates, static function (Node $node) use ($family, $downSince): bool {
            $since = $node->health($family)->since;
            return $since === null || $since >= $downSince;
        }));
        return $last !== [] ? $last : $candidates;
    }
}
