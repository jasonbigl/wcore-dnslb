<?php

namespace Wcore\Dnslb\Notify;

use Wcore\Dnslb\State\Event;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 记录事件：同时写日志和 events 表。warning 及以上的事件稍后会合并发送邮件。
 */
final class EventRecorder
{
    private EventRepository $events;
    private Clock $clock;
    private Logger $logger;

    public function __construct(EventRepository $events, Clock $clock, Logger $logger)
    {
        $this->events = $events;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function info(string $type, string $message, ?string $pool = null, ?string $nodeId = null, array $context = []): void
    {
        $this->record(Event::INFO, $type, $message, $pool, $nodeId, $context);
    }

    public function warning(string $type, string $message, ?string $pool = null, ?string $nodeId = null, array $context = []): void
    {
        $this->record(Event::WARNING, $type, $message, $pool, $nodeId, $context);
    }

    public function critical(string $type, string $message, ?string $pool = null, ?string $nodeId = null, array $context = []): void
    {
        $this->record(Event::CRITICAL, $type, $message, $pool, $nodeId, $context);
    }

    private function record(string $level, string $type, string $message, ?string $pool, ?string $nodeId, array $context): void
    {
        $this->events->add(new Event($this->clock->now(), $level, $type, $message, $pool, $nodeId, $context));

        $logContext = array_filter(['type' => $type, 'pool' => $pool, 'node' => $nodeId], static function ($v) {
            return $v !== null;
        }) + $context;
        if ($level === Event::INFO) {
            $this->logger->info($message, $logContext);
        } elseif ($level === Event::WARNING) {
            $this->logger->warning($message, $logContext);
        } else {
            $this->logger->error($message, $logContext);
        }
    }
}
