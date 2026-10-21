<?php

namespace Wcore\Dnslb\Cloudflare;

/**
 * Cloudflare API 返回的错误（success 为 false 或 HTTP 状态码非 2xx）。
 */
class CloudflareException extends \RuntimeException
{
    private int $httpStatus;
    /** @var int[] Cloudflare 错误码，例如 81057（记录已存在） */
    private array $errorCodes;

    /**
     * @param int[] $errorCodes
     */
    public function __construct(string $message, int $httpStatus, array $errorCodes = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->errorCodes = $errorCodes;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return int[]
     */
    public function getErrorCodes(): array
    {
        return $this->errorCodes;
    }
}
