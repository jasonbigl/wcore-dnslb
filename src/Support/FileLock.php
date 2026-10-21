<?php

namespace Wcore\Dnslb\Support;

/**
 * 基于 flock 的进程锁。进程退出时操作系统会自动释放，不会留下死锁。
 */
final class FileLock
{
    private string $path;
    /** @var resource|null */
    private $handle;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * @param bool $wait true 时阻塞等待；false 时拿不到锁立即返回 false
     */
    public function acquire(bool $wait = false): bool
    {
        if ($this->handle !== null) {
            return true;
        }
        $dir = dirname($this->path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create lock directory: {$dir}");
        }
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open lock file: {$this->path}");
        }
        $flags = $wait ? LOCK_EX : LOCK_EX | LOCK_NB;
        if (!flock($handle, $flags)) {
            fclose($handle);
            return false;
        }
        $this->handle = $handle;
        return true;
    }

    public function release(): void
    {
        if ($this->handle === null) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    /**
     * 持锁执行回调，执行完（包括抛异常）自动释放。
     *
     * @return mixed 回调的返回值
     */
    public function synchronized(callable $callback)
    {
        $this->acquire(true);
        try {
            return $callback();
        } finally {
            $this->release();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
