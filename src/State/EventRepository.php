<?php

namespace Wcore\Dnslb\State;

final class EventRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function add(Event $event): Event
    {
        $this->db->execute(
            'INSERT INTO events (at, level, type, pool, node_id, message, context, notified) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $event->at,
                $event->level,
                $event->type,
                $event->pool,
                $event->nodeId,
                $event->message,
                $event->context === [] ? null : json_encode($event->context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $event->notified ? 1 : 0,
            ]
        );
        $event->id = (int) $this->db->pdo()->lastInsertId();
        return $event;
    }

    /**
     * 尚未通知、且级别不低于 $minLevel 的事件，按时间先后排列。
     *
     * @return Event[]
     */
    public function pendingNotifications(string $minLevel = Event::WARNING): array
    {
        $levels = array_keys(array_filter(Event::LEVEL_ORDER, static function (int $order) use ($minLevel): bool {
            return $order >= Event::LEVEL_ORDER[$minLevel];
        }));
        $rows = $this->db->fetchAll(
            'SELECT * FROM events WHERE notified = 0 AND level IN (' . implode(',', array_fill(0, count($levels), '?')) . ') ORDER BY id',
            $levels
        );
        return array_map([Event::class, 'fromRow'], $rows);
    }

    /**
     * @param int[] $ids
     */
    public function markNotified(array $ids): void
    {
        foreach (array_chunk($ids, 500) as $chunk) {
            $this->db->execute(
                'UPDATE events SET notified = 1 WHERE id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
                $chunk
            );
        }
    }

    /**
     * 最近的事件，新的在前。
     *
     * @return Event[]
     */
    public function recent(int $limit, ?string $pool = null, ?string $nodeId = null): array
    {
        $where = [];
        $params = [];
        if ($pool !== null) {
            $where[] = 'pool = ?';
            $params[] = $pool;
        }
        if ($nodeId !== null) {
            $where[] = 'node_id = ?';
            $params[] = $nodeId;
        }
        $sql = 'SELECT * FROM events' . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . ' ORDER BY id DESC LIMIT ' . max(1, $limit);
        return array_map([Event::class, 'fromRow'], $this->db->fetchAll($sql, $params));
    }

    /**
     * 某类事件在指定时间之后发生的次数。
     */
    public function countSince(string $type, int $since): int
    {
        $row = $this->db->fetchOne('SELECT COUNT(*) AS n FROM events WHERE type = ? AND at >= ?', [$type, $since]);
        return (int) ($row['n'] ?? 0);
    }

    /**
     * 删除早于指定时间的事件（无论是否已通知，避免未配置通知时无限增长）。
     */
    public function purge(int $before): int
    {
        return $this->db->execute('DELETE FROM events WHERE at < ?', [$before]);
    }
}
