<?php

namespace Wcore\Dnslb\State;

/**
 * 记录每类告警最近一次发送时间，用于限频。
 */
final class AlertLog
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function lastSentAt(string $key): ?int
    {
        $row = $this->db->fetchOne('SELECT last_sent_at FROM alerts_sent WHERE alert_key = ?', [$key]);
        return $row === null ? null : (int) $row['last_sent_at'];
    }

    public function shouldSend(string $key, int $now, int $throttle): bool
    {
        $last = $this->lastSentAt($key);
        return $last === null || $now - $last >= $throttle;
    }

    public function markSent(string $key, int $now): void
    {
        $this->db->execute('INSERT OR REPLACE INTO alerts_sent (alert_key, last_sent_at) VALUES (?, ?)', [$key, $now]);
    }

    public function clear(string $key): void
    {
        $this->db->execute('DELETE FROM alerts_sent WHERE alert_key = ?', [$key]);
    }
}
