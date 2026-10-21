<?php

namespace Wcore\Dnslb\Http;

final class Request
{
    public string $method;
    public string $url;
    /** @var array<string,string> 请求头，键为头名称 */
    public array $headers;
    public string $body;
    /** 整个请求的超时（秒） */
    public float $timeout = 30.0;
    /** 建立连接的超时（秒） */
    public float $connectTimeout = 10.0;
    /** @var string[] CURLOPT_RESOLVE 条目，例如 "app.example.com:443:1.2.3.4"；设置后使用独立连接，不复用 */
    public array $resolve = [];
    /** 强制 IP 协议版本：'v4'、'v6' 或 null（不限制） */
    public ?string $ipVersion = null;
    public bool $verifyTls = true;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], string $body = '')
    {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * 按名称（不区分大小写）查找请求头。
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
