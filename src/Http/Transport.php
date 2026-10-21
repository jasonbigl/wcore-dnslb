<?php

namespace Wcore\Dnslb\Http;

interface Transport
{
    /**
     * 发送单个请求。HTTP 错误状态码正常返回 Response，只有网络层错误才抛异常。
     *
     * @throws HttpException
     */
    public function send(Request $request): Response;

    /**
     * 并行发送多个请求，最多同时进行 $concurrency 个。
     *
     * @param array<string|int,Request> $requests
     * @return array<string|int,Response|HttpException> 与 $requests 键一一对应
     */
    public function sendAll(array $requests, int $concurrency): array;
}
