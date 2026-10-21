<?php

namespace Wcore\Dnslb\Config;

final class ErrorBag
{
    /** @var string[] */
    private array $errors = [];

    public function add(string $error): void
    {
        $this->errors[] = $error;
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return string[]
     */
    public function all(): array
    {
        return $this->errors;
    }
}
