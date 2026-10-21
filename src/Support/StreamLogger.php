<?php

namespace Wcore\Dnslb\Support;

/**
 * 按行输出日志到文件或标准输出：
 * 2026-10-19T08:00:00+00:00 [info] message {"key":"value"}
 */
final class StreamLogger implements Logger
{
    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    /** @var resource */
    private $stream;
    private int $minLevel;
    private string $prefix;

    /**
     * @param string|null $file   日志文件路径；null 时写到 STDOUT
     * @param string      $level  最低输出级别
     * @param string      $prefix 每行前缀，例如命令名
     */
    public function __construct(?string $file = null, string $level = 'info', string $prefix = '')
    {
        if (!isset(self::LEVELS[$level])) {
            throw new \InvalidArgumentException("Unknown log level: {$level}");
        }
        $stream = $file === null ? fopen('php://stdout', 'wb') : fopen($file, 'ab');
        if ($stream === false) {
            throw new \RuntimeException("Cannot open log file: {$file}");
        }
        $this->stream = $stream;
        $this->minLevel = self::LEVELS[$level];
        $this->prefix = $prefix;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        if (self::LEVELS[$level] < $this->minLevel) {
            return;
        }
        $line = date('c') . " [{$level}] ";
        if ($this->prefix !== '') {
            $line .= "[{$this->prefix}] ";
        }
        $line .= $message;
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        fwrite($this->stream, $line . "\n");
    }
}
