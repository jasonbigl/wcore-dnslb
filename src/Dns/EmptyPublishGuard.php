<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 不把对外名称同步成没有记录：没有可以发布的节点（例如改名或替换了全部池、所有节点同时丢失）而现有记录不为空时，
 * 保留现有记录不动，等又有节点可以发布时再切换。仍在记录中的旧节点因此不会开始排空计时，会继续服务到切换完成。
 * 进入和解除时各记录一条事件。
 */
final class EmptyPublishGuard
{
    private const META_KEY = 'dns_empty_held';

    private string $name;
    private Meta $meta;
    private EventRecorder $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(string $name, Meta $meta, EventRecorder $events, Clock $clock, Logger $logger)
    {
        $this->name = $name;
        $this->meta = $meta;
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    /**
     * @param bool $desiredEmpty   对外名称上没有要发布的记录
     * @param bool $publishedEmpty 对外名称上现在没有 dnslb 的记录
     * @return bool 是否保留现有记录、跳过本次同步
     */
    public function hold(bool $desiredEmpty, bool $publishedEmpty): bool
    {
        $now = $this->clock->now();
        $since = $this->meta->get(self::META_KEY);
        if ($desiredEmpty && !$publishedEmpty) {
            if ($since === null) {
                $this->meta->set(self::META_KEY, (string) $now);
                $this->events->critical(
                    'dns_empty_held',
                    "没有可以发布的节点（例如改名或替换了全部池），保留 {$this->name} 现有的记录不删除，有节点上线后再切换"
                );
            } else {
                $this->logger->warning("No nodes to publish; keeping the existing records of {$this->name}", ['since' => (int) $since]);
            }
            return true;
        }
        if ($since !== null) {
            $this->meta->delete(self::META_KEY);
            $this->events->info('dns_empty_released', "{$this->name} 已有节点可以发布，恢复正常同步", null, null, [
                'held_seconds' => $now - (int) $since,
            ]);
        }
        return false;
    }
}
