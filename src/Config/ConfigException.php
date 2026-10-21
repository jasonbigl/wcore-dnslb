<?php

namespace Wcore\Dnslb\Config;

final class ConfigException extends \RuntimeException
{
    /** @var string[] */
    private array $errors;

    /**
     * @param string[] $errors 每条错误都带配置路径，例如 "pools.tokyo.min: must be an integer"
     */
    public function __construct(array $errors)
    {
        $this->errors = $errors;
        parent::__construct("Invalid configuration:\n  - " . implode("\n  - ", $errors));
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
