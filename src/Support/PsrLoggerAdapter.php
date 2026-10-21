<?php

namespace Wcore\Dnslb\Support;

/**
 * 把日志转发给任意 PSR-3 风格的 logger（例如 Laravel 的 Log::channel()），
 * 只要求对方有 debug / info / warning / error 方法，因此不需要依赖 psr/log。
 */
final class PsrLoggerAdapter implements Logger
{
    /** @var object */
    private $logger;
    private string $prefix;

    /**
     * @param object $logger
     * @param string $prefix 每条消息的前缀，例如 "[dnslb:tick] "
     */
    public function __construct($logger, string $prefix = '')
    {
        foreach (['debug', 'info', 'warning', 'error'] as $method) {
            if (!is_object($logger) || !is_callable([$logger, $method])) {
                throw new \InvalidArgumentException('Logger must be a PSR-3 style object with debug / info / warning / error methods');
            }
        }
        $this->logger = $logger;
        $this->prefix = $prefix;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($this->prefix . $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($this->prefix . $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($this->prefix . $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($this->prefix . $message, $context);
    }
}
