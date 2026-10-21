<?php

namespace Wcore\Dnslb\Provider;

/**
 * 云厂商明确拒绝了请求（请求未生效）。
 */
class ProviderException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
