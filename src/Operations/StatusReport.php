<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Lifecycle\Rollout;
use Wcore\Dnslb\State\Event;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\OrphanInstance;
use Wcore\Dnslb\State\PoolState;

/**
 * 各池、各节点的状态和最近事件（只读本地状态库，不调用外部接口）。
 */
final class StatusReport
{
    private const RECENT_EVENTS = 15;

    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    /**
     * @return array{
     *   service: string,
     *   dns: array{provider: string, name: string, routing: string, ipv6: bool},
     *   flags: array<array{flag: string, family: string, since: int}>,
     *   pools: array<array<string,mixed>>,
     *   orphans: array<array{provider: string, region: string, instance_id: string, node_id: ?string, first_seen_at: int}>,
     *   events: array<array<string,mixed>>
     * }
     */
    public function collect(): array
    {
        $app = $this->app;
        $config = $app->config();
        $meta = $app->meta();
        $now = $app->clock()->now();

        $flags = [];
        foreach ([Node::FAMILY_V4, Node::FAMILY_V6] as $family) {
            foreach (['frozen' => "frozen_{$family}", 'canary_down' => "canary_down_{$family}"] as $flag => $key) {
                $since = $meta->get($key);
                if ($since !== null) {
                    $flags[] = ['flag' => $flag, 'family' => $family, 'since' => (int) $since];
                }
            }
        }

        $poolStates = $app->pools()->all();
        $nodesByPool = [];
        foreach ($app->nodes()->live() as $node) {
            $nodesByPool[$node->pool][] = $node;
        }

        $pools = [];
        foreach (array_unique(array_merge(array_keys($config->pools), array_keys($nodesByPool))) as $name) {
            $poolConfig = $config->pools[$name] ?? null;
            $state = $poolStates[$name] ?? null;
            $pools[] = [
                'name' => $name,
                'configured' => $poolConfig !== null,
                'latency_region' => $poolConfig === null ? null : $poolConfig->latencyRegion,
                'min' => $poolConfig === null ? null : $poolConfig->min,
                'max' => $poolConfig === null ? null : $poolConfig->max,
                'paused' => $state !== null && $state->isPaused($now),
                'manual_paused' => $state !== null && $state->manualPaused,
                'paused_until' => $state !== null && $state->pausedUntil !== null && $state->pausedUntil > $now ? $state->pausedUntil : null,
                'v4_down_since' => $state === null ? null : $state->v4DownSince,
                'v6_down_since' => $state === null ? null : $state->v6DownSince,
                'rollout' => self::rollout($state, $nodesByPool[$name] ?? []),
                'nodes' => array_map(static function (Node $node): array {
                    return [
                        'node_id' => $node->nodeId,
                        'state' => $node->state,
                        'provider' => $node->provider,
                        'region' => $node->providerRegion,
                        'instance_id' => $node->instanceId,
                        'ipv4' => $node->ipv4,
                        'ipv6' => $node->ipv6,
                        'v4_healthy' => $node->v4->healthy,
                        'v6_healthy' => $node->v6->healthy,
                        'health_error' => $node->v4->error ?? $node->v6->error,
                        'created_at' => $node->createdAt,
                        'state_changed_at' => $node->stateChangedAt,
                        'last_error' => $node->lastError,
                        'outdated' => $node->outdated,
                    ];
                }, $nodesByPool[$name] ?? []),
            ];
        }

        $events = array_map(static function (Event $event): array {
            return [
                'at' => $event->at,
                'level' => $event->level,
                'type' => $event->type,
                'pool' => $event->pool,
                'node_id' => $event->nodeId,
                'message' => $event->message,
            ];
        }, $app->events()->recent(self::RECENT_EVENTS));

        return [
            'service' => $config->service,
            'dns' => [
                'provider' => $config->dns->provider,
                'name' => $config->dns->name,
                // Route 53：池内路由方式（multivalue / weighted）；Cloudflare：proxied / dns-only
                'routing' => $config->dns->isCloudflare() ? ($config->dns->proxied ? 'proxied' : 'dns-only') : $config->dns->poolRouting,
                'ipv6' => $config->dns->ipv6,
            ],
            'flags' => $flags,
            'pools' => $pools,
            // 已确认的孤儿实例（见 Dnslb::cleanupOrphans()）
            'orphans' => array_map(static function (OrphanInstance $orphan): array {
                return $orphan->toArray();
            }, $app->orphanTracker()->confirmed()),
            'events' => $events,
        ];
    }

    /**
     * 进行中的发布：total 为开始时的旧节点数，remaining 为还没替换完的旧节点数（其中 replacing 台正在替换）。
     *
     * @param Node[] $nodes
     * @return array{started_at: int, batch: int, total: int, remaining: int, replacing: int}|null
     */
    private static function rollout(?PoolState $state, array $nodes): ?array
    {
        if ($state === null || $state->rolloutStartedAt === null) {
            return null;
        }
        $remaining = array_filter($nodes, static function (Node $node): bool {
            return $node->outdated && in_array($node->state, Rollout::REMAINING_STATES, true);
        });
        return [
            'started_at' => $state->rolloutStartedAt,
            'batch' => $state->rolloutBatch,
            'total' => $state->rolloutTotal,
            'remaining' => count($remaining),
            'replacing' => count(array_filter($remaining, static function (Node $node): bool {
                return $node->state === Node::REPLACING;
            })),
        ];
    }
}
