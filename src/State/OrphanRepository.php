<?php

namespace Wcore\Dnslb\State;

/**
 * 孤儿实例 / 弹性 IP 表（只由 tick 进程和持有 tick 锁的手动操作写）。
 */
final class OrphanRepository
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return OrphanInstance[] 按首次发现时间排序
     */
    public function all(): array
    {
        return array_map([OrphanInstance::class, 'fromRow'], $this->db->fetchAll('SELECT * FROM orphans ORDER BY first_seen_at, instance_id'));
    }

    /**
     * 用某个云厂商本次列出的可疑实例（或弹性 IP，由 $kind 指定）更新记录：已有的保留首次发现时间，
     * 不再可疑（被引用或已删除）的删掉。只影响同一种 kind 的记录。
     *
     * @param OrphanInstance[] $suspects
     */
    public function replaceForProvider(string $provider, array $suspects, string $kind = OrphanInstance::KIND_INSTANCE): void
    {
        $this->db->transaction(function () use ($provider, $suspects, $kind): void {
            $ids = [];
            foreach ($suspects as $orphan) {
                $ids[] = $orphan->instanceId;
                $this->db->execute(
                    'INSERT OR IGNORE INTO orphans (provider, instance_id, region, node_id, first_seen_at, kind) VALUES (?, ?, ?, ?, ?, ?)',
                    [$provider, $orphan->instanceId, $orphan->region, $orphan->nodeId, $orphan->firstSeenAt, $kind]
                );
            }
            $sql = 'DELETE FROM orphans WHERE provider = ? AND kind = ?';
            if ($ids !== []) {
                $sql .= ' AND instance_id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            }
            $this->db->execute($sql, array_merge([$provider, $kind], $ids));
        });
    }

    public function markReported(OrphanInstance $orphan, int $now): void
    {
        $orphan->reportedAt = $now;
        $this->db->execute('UPDATE orphans SET reported_at = ? WHERE provider = ? AND instance_id = ?', [$now, $orphan->provider, $orphan->instanceId]);
    }

    public function delete(OrphanInstance $orphan): void
    {
        $this->db->execute('DELETE FROM orphans WHERE provider = ? AND instance_id = ?', [$orphan->provider, $orphan->instanceId]);
    }
}
