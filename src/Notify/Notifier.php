<?php

namespace Wcore\Dnslb\Notify;

use Wcore\Dnslb\Aws\Ses\SesClient;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\State\AlertLog;
use Wcore\Dnslb\State\Event;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\Support\Clock;
use Wcore\Dnslb\Support\Logger;

/**
 * 把待通知的事件合并成一封邮件发送（每个 tick 最多一封）。
 *
 * - 发送 warning / critical 事件，以及已告警问题的恢复通知；
 * - 同一告警（类型 + 池 + 节点）在 notify.throttle 秒内只发一次；发出恢复通知后，同一对象再次故障立即告警；
 * - 发送失败时事件保持待发送，下个 tick 重试；超过一天仍未发出的不再发送。
 */
final class Notifier
{
    /** 恢复通知 => 对应的故障告警 */
    private const RECOVERY_TYPES = [
        'pool_recovered' => 'pool_down',
        'global_freeze_lifted' => 'global_freeze',
        'canary_recovered' => 'canary_down',
        'dns_empty_released' => 'dns_empty_held',
    ];
    private const MAX_PENDING_AGE = 86400;
    private const LEVEL_LABELS = [Event::CRITICAL => 'CRITICAL', Event::WARNING => 'WARNING', Event::INFO => 'RECOVERY'];

    private Config $config;
    private SesClient $ses;
    private EventRepository $events;
    private AlertLog $alerts;
    private Clock $clock;
    private Logger $logger;

    public function __construct(Config $config, SesClient $ses, EventRepository $events, AlertLog $alerts, Clock $clock, Logger $logger)
    {
        $this->config = $config;
        $this->ses = $ses;
        $this->events = $events;
        $this->alerts = $alerts;
        $this->clock = $clock;
        $this->logger = $logger;
    }

    public function flush(): void
    {
        $pending = $this->events->pendingNotifications(Event::INFO);
        if ($pending === []) {
            return;
        }
        $notify = $this->config->notify;
        $now = $this->clock->now();

        $toSend = [];
        $keys = [];
        $handled = [];
        $suppressed = 0;
        foreach ($pending as $event) {
            $handled[] = (int) $event->id;
            $important = $event->level !== Event::INFO || isset(self::RECOVERY_TYPES[$event->type]);
            if ($notify === null || !$important || $now - $event->at > self::MAX_PENDING_AGE) {
                continue;
            }
            $key = self::alertKey($event->type, $event);
            if (!isset($keys[$key]) && !$this->alerts->shouldSend($key, $now, $notify->throttle)) {
                $suppressed++;
                continue;
            }
            $keys[$key] = true;
            $toSend[] = $event;
        }

        if ($toSend !== []) {
            [$subject, $body] = $this->compose($toSend, $suppressed);
            try {
                $this->ses->sendEmail($notify->region, $notify->from, $notify->to, $subject, $body);
            } catch (\Throwable $e) {
                $this->logger->error('Failed to send notification email, will retry next tick: ' . $e->getMessage());
                $sendingIds = array_map(static function (Event $event): int {
                    return (int) $event->id;
                }, $toSend);
                $this->events->markNotified(array_values(array_diff($handled, $sendingIds)));
                return;
            }
            // 按事件顺序记录：最后发出的是恢复通知时，同一对象下次故障不受限频影响
            foreach ($toSend as $event) {
                $this->alerts->markSent(self::alertKey($event->type, $event), $now);
                if (isset(self::RECOVERY_TYPES[$event->type])) {
                    $this->alerts->clear(self::alertKey(self::RECOVERY_TYPES[$event->type], $event));
                }
            }
            $this->logger->info('Notification email sent', ['events' => count($toSend), 'suppressed' => $suppressed]);
        }
        $this->events->markNotified($handled);
    }

    private static function alertKey(string $type, Event $event): string
    {
        return implode('|', [$type, $event->pool ?? '', $event->nodeId ?? '']);
    }

    /**
     * @param Event[] $events
     * @return array{0:string,1:string}
     */
    public function compose(array $events, int $suppressed): array
    {
        $notify = $this->config->notify;
        $highest = Event::INFO;
        foreach ($events as $event) {
            if (Event::LEVEL_ORDER[$event->level] > Event::LEVEL_ORDER[$highest]) {
                $highest = $event->level;
            }
        }
        // 标题只取第一条消息的前 60 个字符（按 UTF-8 字符计）
        $first = $events[0]->message;
        if (preg_match('/^.{60}/us', $first, $m) && $m[0] !== $first) {
            $first = $m[0] . '…';
        }
        $subject = sprintf('%s %s %s%s', $notify->subjectPrefix, self::LEVEL_LABELS[$highest], $first, count($events) > 1 ? ' 等 ' . count($events) . ' 条' : '');

        $lines = ["服务：{$this->config->service}（{$this->config->dns->name}）", ''];
        foreach ($events as $event) {
            $where = $event->pool ?? '';
            if ($event->nodeId !== null) {
                $where .= '/' . $event->nodeId;
            }
            $lines[] = sprintf(
                '[%s] %s UTC  %s%s',
                self::LEVEL_LABELS[$event->level],
                gmdate('Y-m-d H:i:s', $event->at),
                $where === '' ? '' : "{$where}  ",
                $event->message
            );
        }
        if ($suppressed > 0) {
            $lines[] = '';
            $lines[] = "另有 {$suppressed} 条同类告警在限频期内（{$notify->throttle} 秒），未重复发送。";
        }
        $lines[] = '';
        $lines[] = '查看详情：Dnslb::status()';
        return [$subject, implode("\n", $lines) . "\n"];
    }
}
