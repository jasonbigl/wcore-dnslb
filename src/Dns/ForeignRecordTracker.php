<?php

namespace Wcore\Dnslb\Dns;

use Wcore\Dnslb\Notify\EventRecorder;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 对外名称上不由 dnslb 管理的 A / AAAA / CNAME 记录（外来记录）的事件：
 * - 接管后又出现外来记录时告警一次（它们会与节点一起分到流量），全部消失后记录一条恢复事件；
 * - takeover() 替换外来记录后记录一条事件，留下审计记录。
 * 接管之前的外来记录是正常状态（等待 takeover），只写日志，并记下"等待接管"（见 takeoverPending()）。
 */
final class ForeignRecordTracker
{
    private const META_KEY = 'dns_foreign_records';
    private const PENDING_KEY = 'dns_takeover_pending';

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
     * 每次同步时调用。
     *
     * @param string[] $foreign  外来记录的描述，例如 "A 203.0.113.9"
     * @param bool $takenOver    名称下已经有 dnslb 发布的记录
     */
    public function observe(array $foreign, bool $takenOver): void
    {
        $now = $this->clock->now();
        $pending = $foreign !== [] && !$takenOver;
        if ($pending !== $this->takeoverPending()) {
            if ($pending) {
                $this->meta->set(self::PENDING_KEY, (string) $now);
            } else {
                $this->meta->delete(self::PENDING_KEY);
            }
        }

        $since = $this->meta->get(self::META_KEY);
        if ($foreign === []) {
            if ($since !== null) {
                $this->meta->delete(self::META_KEY);
                $this->events->info(
                    'dns_foreign_records_removed',
                    sprintf('%s 上不由 dnslb 管理的记录已全部删除（存在了 %d 秒）', $this->name, $now - (int) $since)
                );
            }
            return;
        }
        if (!$takenOver) {
            return;
        }
        if ($since === null) {
            $this->meta->set(self::META_KEY, (string) $now);
            $this->events->warning('dns_foreign_records', sprintf(
                '%s 上出现了 %d 条不由 dnslb 管理的记录（%s），会与节点一起分到流量。确认无用后删除，或调用 Dnslb::takeover() 替换',
                $this->name,
                count($foreign),
                implode('、', $foreign)
            ));
            return;
        }
        $this->logger->warning(sprintf(
            '%s also has %d record(s) not managed by dnslb that receive traffic too; remove them or call Dnslb::takeover()',
            $this->name,
            count($foreign)
        ));
    }

    /**
     * 对外名称上有外来记录、还没有接管（最近一次同步时的状态）：这期间上线的节点不会接到对外流量。
     */
    public function takeoverPending(): bool
    {
        return $this->meta->get(self::PENDING_KEY) !== null;
    }

    /**
     * takeover() 提交成功后调用。
     *
     * @param string[] $replaced 删除的外来记录的描述
     */
    public function recordTakeover(array $replaced): void
    {
        $this->meta->delete(self::PENDING_KEY);
        $this->events->info('dns_takeover', sprintf(
            '接管 %s：删除了 %d 条不由 dnslb 管理的记录（%s），解析改由 dnslb 管理',
            $this->name,
            count($replaced),
            implode('、', $replaced)
        ));
    }
}
