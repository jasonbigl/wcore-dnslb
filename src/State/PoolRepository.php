<?php

namespace Wcore\Dnslb\State;

/**
 * 池状态表。与 NodeRepository 一样按进程分开写：
 * 整池故障时间只由 saveHealth() 写（health 进程），其余字段只由 save() 写（tick 进程）。
 * 扩缩容用 saveScaling() 只写自己的字段，避免覆盖扩容过程中写入的启动失败计数和自动暂停。
 */
final class PoolRepository
{
    private const HEALTH_COLUMNS = ['v4_down_since', 'v6_down_since'];
    private const SCALING_COLUMNS = ['last_scale_out_at', 'last_scale_in_at', 'out_breach_since', 'in_breach_since'];

    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 读取池状态，不存在时返回全新的默认状态（尚未保存）。
     */
    public function get(string $pool): PoolState
    {
        $row = $this->db->fetchOne('SELECT * FROM pools WHERE pool = ?', [$pool]);
        return $row === null ? new PoolState($pool) : PoolState::fromRow($row);
    }

    /**
     * @return array<string,PoolState>
     */
    public function all(): array
    {
        $result = [];
        foreach ($this->db->fetchAll('SELECT * FROM pools ORDER BY pool') as $row) {
            $result[$row['pool']] = PoolState::fromRow($row);
        }
        return $result;
    }

    /**
     * 保存扩缩容、暂停等字段（不含整池故障时间）。
     */
    public function save(PoolState $state): void
    {
        $this->upsert(array_diff_key($state->toRow(), array_flip(self::HEALTH_COLUMNS)));
    }

    /**
     * 只保存扩缩容时间和指标超限起始时间。
     */
    public function saveScaling(PoolState $state): void
    {
        $this->upsert(array_intersect_key($state->toRow(), array_flip(array_merge(['pool'], self::SCALING_COLUMNS))));
    }

    /**
     * 只保存整池故障时间。
     */
    public function saveHealth(PoolState $state): void
    {
        $this->upsert(array_intersect_key($state->toRow(), array_flip(array_merge(['pool'], self::HEALTH_COLUMNS))));
    }

    private function upsert(array $row): void
    {
        $this->db->transaction(function () use ($row): void {
            $this->db->execute('INSERT OR IGNORE INTO pools (pool) VALUES (?)', [$row['pool']]);
            $assignments = [];
            $params = [':pool' => $row['pool']];
            foreach ($row as $column => $value) {
                if ($column !== 'pool') {
                    $assignments[] = "{$column} = :{$column}";
                    $params[":{$column}"] = $value;
                }
            }
            $this->db->execute('UPDATE pools SET ' . implode(', ', $assignments) . ' WHERE pool = :pool', $params);
        });
    }
}
