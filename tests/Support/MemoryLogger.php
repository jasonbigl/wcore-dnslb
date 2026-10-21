<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Support\Logger;

final class MemoryLogger implements Logger
{
    /** @var string[] "level: message" */
    public array $lines = [];

    public function debug(string $message, array $context = []): void
    {
        $this->lines[] = "debug: {$message}";
    }

    public function info(string $message, array $context = []): void
    {
        $this->lines[] = "info: {$message}";
    }

    public function warning(string $message, array $context = []): void
    {
        $this->lines[] = "warning: {$message}";
    }

    public function error(string $message, array $context = []): void
    {
        $this->lines[] = "error: {$message}";
    }

    /**
     * @return string[]
     */
    public function matching(string $needle): array
    {
        return array_values(array_filter($this->lines, static function (string $line) use ($needle): bool {
            return strpos($line, $needle) !== false;
        }));
    }
}
