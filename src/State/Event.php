<?php

namespace Wcore\Dnslb\State;

/**
 * 审计事件。warning 及以上级别会通过邮件通知。
 */
final class Event
{
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const CRITICAL = 'critical';

    public const LEVEL_ORDER = [self::INFO => 0, self::WARNING => 1, self::CRITICAL => 2];

    public ?int $id = null;
    public int $at;
    public string $level;
    /** 事件类型，例如 scale_out、node_failed；同时作为告警限频的键的一部分 */
    public string $type;
    public ?string $pool;
    public ?string $nodeId;
    public string $message;
    public array $context;
    public bool $notified = false;

    public function __construct(int $at, string $level, string $type, string $message, ?string $pool = null, ?string $nodeId = null, array $context = [])
    {
        if (!isset(self::LEVEL_ORDER[$level])) {
            throw new \InvalidArgumentException("Unknown event level: {$level}");
        }
        $this->at = $at;
        $this->level = $level;
        $this->type = $type;
        $this->message = $message;
        $this->pool = $pool;
        $this->nodeId = $nodeId;
        $this->context = $context;
    }

    public static function fromRow(array $row): self
    {
        $context = $row['context'] === null ? [] : json_decode($row['context'], true);
        $event = new self((int) $row['at'], $row['level'], $row['type'], $row['message'], $row['pool'], $row['node_id'], is_array($context) ? $context : []);
        $event->id = (int) $row['id'];
        $event->notified = (bool) $row['notified'];
        return $event;
    }
}
