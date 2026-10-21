<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\PoolConfig;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Provider\ProviderInterface;
use Wcore\Dnslb\Provider\ProviderRegistry;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 创建节点：先写数据库，再按池的 launch 顺序尝试创建实例。每次调用建机接口前先保存当前方式。
 *
 * - 云厂商明确拒绝（容量不足、配额等）：换下一种方式；
 * - 网络错误、重试用尽仍是服务端错误或限流（请求是否生效未知）：保持 PROVISIONING，下次 tick 先按节点 ID 查找，
 *   避免在别处重复建机（见 Reconciler::advanceProvisioning）。
 */
final class Provisioner
{
    private const ID_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    private Config $config;
    private ProviderRegistry $providers;
    private NodeRepository $nodes;
    private UserDataRenderer $renderer;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(
        Config $config,
        ProviderRegistry $providers,
        NodeRepository $nodes,
        UserDataRenderer $renderer,
        EventRecorder $events,
        Clock $clock,
        Logger $logger
    ) {
        $this->config = $config;
        $this->providers = $providers;
        $this->nodes = $nodes;
        $this->renderer = $renderer;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function create(PoolConfig $pool, ?string $replacementFor = null): Node
    {
        $now = $this->clock->now();
        $spec = $pool->launch[0];
        $node = new Node($this->newNodeId($pool->name), $pool->name, $spec->provider, $spec->region, 0, $spec->weight, $now);
        $node->replacementFor = $replacementFor;
        $this->nodes->insert($node, $now);
        $this->launch($node, $pool, 0);
        return $node;
    }

    /**
     * 从第 $from 种 launch 方式开始尝试创建实例。
     */
    public function launch(Node $node, PoolConfig $pool, int $from): void
    {
        $errors = [];
        for ($i = $from; $i < count($pool->launch); $i++) {
            $spec = $pool->launch[$i];
            if ($node->launchIndex !== $i || $node->launchStartedAt === null) {
                $node->launchStartedAt = $this->clock->now();
            }
            $node->provider = $spec->provider;
            $node->providerRegion = $spec->region;
            $node->launchIndex = $i;
            $node->weight = $spec->weight;
            // 进程在保存建机结果前退出时，恢复流程按这里保存的方式查找实例，不会在别处重复建机
            $this->nodes->save($node, $this->clock->now());

            try {
                $userData = $this->renderer->render([
                    'node_id' => $node->nodeId,
                    'service' => $this->config->service,
                    'pool' => $pool->name,
                    'provider' => $spec->provider,
                    'region' => $spec->region,
                    'latency_region' => $pool->latencyRegion ?? '',
                ]);
                $instance = $this->providers->get($spec->provider)->create($spec, $node->nodeId, $userData, $this->tags($node));
            } catch (ProviderException $e) {
                $errors[] = "{$spec->provider}/{$spec->region}: {$e->getMessage()}";
                $node->lastError = $e->getMessage();
                $this->logger->warning("Launch via {$spec->provider}/{$spec->region} failed: {$e->getMessage()}", ['node' => $node->nodeId]);
                continue;
            } catch (HttpException | OutcomeUnknownException $e) {
                $node->lastError = 'create request outcome unknown, will look it up: ' . $e->getMessage();
                $this->nodes->save($node, $this->clock->now());
                $this->logger->warning("Launch request for {$node->nodeId} failed on the network; will check whether the instance exists next tick", [
                    'error' => $e->getMessage(),
                ]);
                return;
            }

            $this->adopt($node, $instance);
            $message = "创建实例 {$spec->provider}/{$spec->region}（{$instance->id}）";
            if ($errors !== []) {
                $message .= '，前面的方式失败：' . implode('；', $errors);
            }
            $this->events->info('node_created', $message, $node->pool, $node->nodeId);
            return;
        }

        $node->transition(Node::FAILED, $this->clock->now());
        $this->nodes->save($node, $this->clock->now());
        $this->events->warning('launch_failed', '所有建机方式都失败：' . implode('；', $errors), $node->pool, $node->nodeId);
    }

    /**
     * 记录实例信息并进入 BOOTING。
     */
    public function adopt(Node $node, Instance $instance): void
    {
        $now = $this->clock->now();
        $node->instanceId = $instance->id;
        $node->ipv4 = $instance->ipv4;
        $node->ipv6 = $instance->ipv6;
        $node->lastError = null;
        $node->transition(Node::BOOTING, $now);
        $this->nodes->save($node, $now);
    }

    /**
     * 实例上的管理标签。节点 ID 由各云厂商写入（EC2 的 Name / dnslb:node 标签，Linode 的 label）。
     *
     * @return array<string,string>
     */
    private function tags(Node $node): array
    {
        return [
            ProviderInterface::TAG_SERVICE => $this->config->service,
            ProviderInterface::TAG_POOL => $node->pool,
        ];
    }

    private function newNodeId(string $pool): string
    {
        do {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= self::ID_ALPHABET[random_int(0, strlen(self::ID_ALPHABET) - 1)];
            }
            $id = "{$this->config->service}-{$pool}-{$suffix}";
        } while ($this->nodes->find($id) !== null);
        return $id;
    }
}
