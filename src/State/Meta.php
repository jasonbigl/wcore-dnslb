<?php

namespace Wcore\Dnslb\State;

/**
 * 简单键值存储，例如全局冻结状态。
 */
final class Meta
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function get(string $key): ?string
    {
        $row = $this->db->fetchOne('SELECT value FROM meta WHERE key = ?', [$key]);
        return $row === null ? null : $row['value'];
    }

    public function set(string $key, string $value): void
    {
        $this->db->execute('INSERT OR REPLACE INTO meta (key, value) VALUES (?, ?)', [$key, $value]);
    }

    public function delete(string $key): void
    {
        $this->db->execute('DELETE FROM meta WHERE key = ?', [$key]);
    }
}
