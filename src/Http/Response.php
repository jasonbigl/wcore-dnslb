<?php

namespace Wcore\Dnslb\Http;

final class Response
{
    public int $status;
    /** @var array<string,string> 响应头，键为小写头名称；同名多值以 ", " 连接 */
    public array $headers;
    public string $body;
    /** 请求总耗时（秒） */
    public float $totalTime;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers = [], string $body = '', float $totalTime = 0.0)
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
        $this->totalTime = $totalTime;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * @return mixed
     */
    public function json()
    {
        $data = json_decode($this->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new HttpException('Invalid JSON response: ' . json_last_error_msg());
        }
        return $data;
    }
}
