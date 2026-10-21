<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Http\Transport;

/**
 * 按顺序返回预设响应，并记录收到的请求。
 * 预设项可以是 Response、HttpException，或接收 Request 返回二者之一的回调。
 */
final class FakeTransport implements Transport
{
    /** @var Request[] */
    public array $requests = [];
    private array $queue = [];
    /** @var callable|null 队列为空时使用的默认处理器 */
    private $handler;

    /**
     * 设置默认处理器：接收 Request，返回 Response 或 HttpException。
     */
    public function handle(callable $handler): self
    {
        $this->handler = $handler;
        return $this;
    }

    /**
     * @param Response|HttpException|callable ...$responses
     */
    public function queue(...$responses): self
    {
        foreach ($responses as $response) {
            $this->queue[] = $response;
        }
        return $this;
    }

    public function send(Request $request): Response
    {
        $this->requests[] = $request;
        if ($this->queue === []) {
            if ($this->handler === null) {
                throw new \LogicException("No queued response for {$request->method} {$request->url}");
            }
            $next = $this->handler;
        } else {
            $next = array_shift($this->queue);
        }
        if (is_callable($next)) {
            $next = $next($request);
        }
        if ($next instanceof HttpException) {
            throw $next;
        }
        return $next;
    }

    public function sendAll(array $requests, int $concurrency): array
    {
        $results = [];
        foreach ($requests as $key => $request) {
            try {
                $results[$key] = $this->send($request);
            } catch (HttpException $e) {
                $results[$key] = $e;
            }
        }
        return $results;
    }

    public function lastRequest(): Request
    {
        if ($this->requests === []) {
            throw new \LogicException('No requests were sent');
        }
        return $this->requests[count($this->requests) - 1];
    }
}
