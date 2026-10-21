<?php

namespace Wcore\Dnslb\Aws;

/**
 * AWS 接口返回的错误（HTTP 状态码非 2xx）。
 */
class AwsException extends \RuntimeException
{
    /** 可以重试的错误码：限流、并发冲突、服务端临时故障 */
    private const RETRYABLE_CODES = [
        'Throttling',
        'ThrottlingException',
        'RequestLimitExceeded',
        'RequestThrottled',
        'TooManyRequestsException',
        'PriorRequestNotComplete',
        'ServiceUnavailable',
        'InternalError',
        'InternalFailure',
        'RequestTimeout',
    ];

    /** 虽然以 5xx 返回、但短时间内重试没有意义的错误（例如 EC2 容量不足，应尽快换其他建机方式） */
    private const NON_RETRYABLE_CODES = [
        'InsufficientInstanceCapacity',
        'InsufficientHostCapacity',
        'InsufficientCapacity',
        'InsufficientAddressCapacity',
        'InsufficientReservedInstanceCapacity',
        'Unsupported',
    ];

    private string $errorCode;
    private int $httpStatus;
    private ?string $requestId;

    public function __construct(string $errorCode, string $message, int $httpStatus, ?string $requestId = null)
    {
        parent::__construct("{$errorCode}: {$message}");
        $this->errorCode = $errorCode;
        $this->httpStatus = $httpStatus;
        $this->requestId = $requestId;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function isRetryable(): bool
    {
        if (in_array($this->errorCode, self::NON_RETRYABLE_CODES, true)) {
            return false;
        }
        return in_array($this->errorCode, self::RETRYABLE_CODES, true)
            || $this->httpStatus === 429
            || $this->httpStatus >= 500;
    }
}
