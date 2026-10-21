<?php

namespace Wcore\Dnslb\State;

/**
 * 节点表。health 和 tick 两个进程会同时修改同一个节点，为避免互相覆盖，字段按归属分开写：
 * 健康相关字段只由 saveHealth() 写（health 进程），其余字段只由 save() 写（tick 进程）。
 */
final class NodeRepository
{
    private const HEALTH_COLUMNS = [
        'v4_healthy', 'v4_fails', 'v4_passes', 'v4_since', 'v4_error',
        'v6_healthy', 'v6_fails', 'v6_passes', 'v6_since', 'v6_error',
        'probed_at',
    ];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function find(string $nodeId): ?Node
    {
        $row = $this->db->fetchOne('SELECT * FROM nodes WHERE node_id = ?', [$nodeId]);
        return $row === null ? null : Node::fromRow($row);
    }

    /**
     * 所有未销毁的节点。
     *
     * @return Node[]
     */
    public function live(): array
    {
        return $this->byStates(Node::LIVE_STATES);
    }

    /**
     * @param string[] $states
     * @return Node[]
     */
    public function byStates(array $states, ?string $pool = null): array
    {
        if ($states === []) {
            return [];
        }
        $sql = 'SELECT * FROM nodes WHERE state IN (' . implode(',', array_fill(0, count($states), '?')) . ')';
        $params = array_values($states);
        if ($pool !== null) {
            $sql .= ' AND pool = ?';
            $params[] = $pool;
        }
        return $this->hydrate($this->db->fetchAll($sql . ' ORDER BY created_at, node_id', $params));
    }

    /**
     * @return Node[]
     */
    public function liveInPool(string $pool): array
    {
        return $this->byStates(Node::LIVE_STATES, $pool);
    }

    public function insert(Node $node, int $now): void
    {
        $node->updatedAt = $now;
        $row = $node->toRow();
        $columns = array_keys($row);
        $this->db->execute(
            'INSERT INTO nodes (' . implode(', ', $columns) . ') VALUES (:' . implode(', :', $columns) . ')',
            self::bind($row)
        );
    }

    /**
     * 更新生命周期字段（不含健康字段）。
     */
    public function save(Node $node, int $now): void
    {
        $node->updatedAt = $now;
        $row = array_diff_key($node->toRow(), array_flip(self::HEALTH_COLUMNS));
        $this->update($row);
    }

    /**
     * 只更新健康字段。
     */
    public function saveHealth(Node $node): void
    {
        $row = array_intersect_key($node->toRow(), array_flip(array_merge(['node_id'], self::HEALTH_COLUMNS)));
        $this->update($row);
    }

    /**
     * 为指定节点创建、仍在启动或已在线的替补。
     */
    public function findReplacementOf(string $nodeId): ?Node
    {
        $states = [Node::PROVISIONING, Node::BOOTING, Node::WARMING, Node::IN_SERVICE, Node::REPLACING];
        $row = $this->db->fetchOne(
            'SELECT * FROM nodes WHERE replacement_for = ? AND state IN (' . implode(',', array_fill(0, count($states), '?')) . ')'
                . ' ORDER BY created_at DESC, node_id DESC LIMIT 1',
            array_merge([$nodeId], $states)
        );
        return $row === null ? null : Node::fromRow($row);
    }

    /**
     * 清除池中所有节点的旧版本标记（发布结束或停止时）。
     */
    public function clearOutdated(string $pool): void
    {
        $this->db->execute('UPDATE nodes SET outdated = 0 WHERE pool = ? AND outdated = 1', [$pool]);
    }

    /**
     * 删除早于指定时间销毁的节点记录。
     */
    public function purgeTerminated(int $before): int
    {
        return $this->db->execute('DELETE FROM nodes WHERE state = ? AND state_changed_at < ?', [Node::TERMINATED, $before]);
    }

    private function update(array $row): void
    {
        $assignments = implode(', ', array_map(static function (string $column): string {
            return "{$column} = :{$column}";
        }, array_diff(array_keys($row), ['node_id'])));
        $updated = $this->db->execute("UPDATE nodes SET {$assignments} WHERE node_id = :node_id", self::bind($row));
        if ($updated === 0) {
            throw new \RuntimeException("Node not found: {$row['node_id']}");
        }
    }

    /**
     * @return Node[]
     */
    private function hydrate(array $rows): array
    {
        return array_map([Node::class, 'fromRow'], $rows);
    }

    private static function bind(array $row): array
    {
        $params = [];
        foreach ($row as $column => $value) {
            $params[":{$column}"] = $value;
        }
        return $params;
    }
}
