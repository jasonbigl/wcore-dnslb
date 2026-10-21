<?php

namespace Wcore\Dnslb\Operations;

use Wcore\Dnslb\Application;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\ProviderInterface;
use Wcore\Dnslb\State\Node;

/**
 * 根据云厂商实例标签和 DNS 记录重建状态库（例如控制器机器重装、SQLite 丢失后）。
 * 只补充数据库中缺失的节点，不修改已有节点：在 DNS 中且运行中的实例恢复为在线节点
 * （健康检查会在下一轮重新确认），其余按排空处理。调用方负责持有 tick 锁。
 */
final class StateRebuilder
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function rebuild(): RebuildResult
    {
        $result = new RebuildResult();
        $now = $this->app->clock()->now();
        $inDns = $this->app->dnsSync()->publishedNodeIds();

        foreach ($this->app->providers()->regionsByProvider() as $provider => $regions) {
            try {
                $instances = $this->app->providers()->get($provider)->listManaged($this->app->config()->service, $regions);
            } catch (\Throwable $e) {
                $result->complete = false;
                $result->warnings[] = "无法列出 {$provider} 的实例：{$e->getMessage()}";
                continue;
            }
            foreach ($instances as $instance) {
                $node = $this->restore($instance, isset($inDns[(string) $instance->nodeId]), $now, $result);
                if ($node !== null) {
                    $result->restored[] = $node;
                }
            }
        }
        return $result;
    }

    private function restore(Instance $instance, bool $inDns, int $now, RebuildResult $result): ?Node
    {
        $nodeId = $instance->nodeId;
        if ($nodeId === null || $nodeId === '') {
            $result->warnings[] = "实例 {$instance->provider}/{$instance->id} 没有节点 ID，已跳过";
            return null;
        }
        $existing = $this->app->nodes()->find($nodeId);
        if ($existing !== null) {
            if ($existing->state === Node::TERMINATED) {
                $result->warnings[] = "{$nodeId} 在状态库中已删除，但实例 {$instance->id} 仍存在（{$instance->status}），请手动确认";
            }
            return null;
        }

        $config = $this->app->config();
        $pool = $instance->tags[ProviderInterface::TAG_POOL] ?? '';
        if ($pool === '') {
            $result->warnings[] = "实例 {$instance->provider}/{$instance->id}（{$nodeId}）缺少池标签，按排空处理";
        }
        $launchIndex = 0;
        $weight = 10;
        foreach (($config->pools[$pool]->launch ?? []) as $spec) {
            if ($spec->provider === $instance->provider && $spec->region === $instance->region) {
                $launchIndex = $spec->index;
                $weight = $spec->weight;
                break;
            }
        }

        $node = new Node($nodeId, $pool, $instance->provider, $instance->region, $launchIndex, $weight, $instance->createdAt ?? $now);
        $node->instanceId = $instance->id;
        $node->ipv4 = $instance->ipv4;
        $node->ipv6 = $instance->ipv6;
        $serving = $inDns && $instance->status === Instance::RUNNING && isset($config->pools[$pool]);
        $node->transition($serving ? Node::IN_SERVICE : Node::DRAINING, $now);
        foreach ([$node->v4, $node->v6] as $health) {
            $health->healthy = $serving;
            $health->since = $now;
        }
        $this->app->nodes()->insert($node, $now);
        $this->app->nodes()->saveHealth($node);
        return $node;
    }
}
