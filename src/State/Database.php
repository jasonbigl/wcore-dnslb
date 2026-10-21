<?php

namespace Wcore\Dnslb\State;

use Wcore\Dnslb\Support\FileLock;

/**
 * SQLite 连接。WAL 模式允许 health 和 tick 两个进程同时读写。
 */
final class Database
{
    private const BUSY_TIMEOUT_MS = 10000;
    /** 演练时复制到内存的表 */
    private const TABLES = ['nodes', 'pools', 'meta', 'alerts_sent', 'events', 'orphans'];

    private \PDO $pdo;

    private function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * 打开（不存在则创建）数据库并执行迁移。$path 为 ':memory:' 时使用内存数据库（测试用）。
     *
     * tick、health 和手动操作可能同时首次打开（或升级后首次打开）同一个文件：切换 WAL 和迁移在文件锁内进行，
     * 否则后到的进程会因重复迁移或切换日志模式时的独占锁而失败。
     */
    public static function open(string $path): self
    {
        if ($path === ':memory:') {
            $db = new self(self::connect($path));
            $db->migrate();
            return $db;
        }
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create state directory: {$dir}");
        }
        $pdo = self::connect($path);
        // 每次提交都落盘：状态库记录的是真实的计费资源，断电后不能丢失最近的提交
        $pdo->exec('PRAGMA synchronous = FULL');
        $db = new self($pdo);
        (new FileLock("{$path}.open.lock"))->synchronized(static function () use ($db): void {
            $db->pdo->exec('PRAGMA journal_mode = WAL');
            $db->migrate();
        });
        return $db;
    }

    /**
     * 只读打开现有的数据库：不切换日志模式、不迁移，任何写入都会报错（演练模式读取真实状态用）。
     */
    public static function openReadOnly(string $path): self
    {
        $db = new self(self::connect($path));
        $db->pdo->exec('PRAGMA query_only = ON');
        $latest = count(Schema::migrations());
        if ($db->schemaVersion() > $latest) {
            throw new \RuntimeException("State database schema version {$db->schemaVersion()} is newer than this package supports ({$latest})");
        }
        return $db;
    }

    /**
     * 复制一份到内存数据库（演练模式使用：可以照常读写，但不影响真实状态）。
     * 按列名复制，源库可以是较旧的表结构。
     */
    public function cloneToMemory(): self
    {
        $copy = self::open(':memory:');
        // 在一个读事务中复制，得到各表一致的快照
        $this->pdo->exec('BEGIN');
        try {
            $existing = array_column($this->fetchAll("SELECT name FROM sqlite_master WHERE type = 'table'"), 'name');
            foreach (array_intersect(self::TABLES, $existing) as $table) {
                foreach ($this->fetchAll("SELECT * FROM {$table}") as $row) {
                    $columns = array_keys($row);
                    $copy->execute(
                        "INSERT INTO {$table} (" . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')',
                        array_values($row)
                    );
                }
            }
        } finally {
            $this->pdo->exec('COMMIT');
        }
        return $copy;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    /**
     * 在写事务中执行回调。使用 BEGIN IMMEDIATE 立即拿写锁，
     * 避免两个进程都先读后写时发生锁升级冲突。
     *
     * @return mixed 回调的返回值
     */
    public function transaction(callable $callback)
    {
        if ($this->pdo->inTransaction()) {
            return $callback();
        }
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $result = $callback();
            $this->pdo->exec('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }
    }

    /**
     * @return array[]
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /**
     * @return int 受影响的行数
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public function schemaVersion(): int
    {
        return (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
    }

    private static function connect(string $path): \PDO
    {
        $pdo = new \PDO('sqlite:' . $path, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT => (int) ceil(self::BUSY_TIMEOUT_MS / 1000),
        ]);
        $pdo->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);
        return $pdo;
    }

    private function migrate(): void
    {
        $migrations = Schema::migrations();
        $current = $this->schemaVersion();
        $latest = count($migrations);
        if ($current > $latest) {
            throw new \RuntimeException("State database schema version {$current} is newer than this package supports ({$latest})");
        }
        for ($version = $current + 1; $version <= $latest; $version++) {
            $this->transaction(function () use ($migrations, $version): void {
                foreach ($migrations[$version - 1] as $sql) {
                    $this->pdo->exec($sql);
                }
                $this->pdo->exec('PRAGMA user_version = ' . $version);
            });
        }
    }
}
