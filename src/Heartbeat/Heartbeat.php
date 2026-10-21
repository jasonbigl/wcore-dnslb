<?php

namespace Wcore\Dnslb\Heartbeat;

use Wcore\Dnslb\Config\HeartbeatConfig;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Support\Logger;

/**
 * 每次 tick / health 成功运行后发送心跳（tick 有步骤失败、health 一轮都没完成时不发送），配合外部监控在控制器失联或持续出错时告警。
 * URL 和请求体中可用 {{service}}、{{cmd}} 占位符。发送失败只记录日志。
 */
final class Heartbeat
{
    private ?HeartbeatConfig $config;
    private Transport $transport;
    private string $service;
    private Logger $logger;

    public function __construct(?HeartbeatConfig $config, Transport $transport, string $service, Logger $logger)
    {
        $this->config = $config;
        $this->transport = $transport;
        $this->service = $service;
        $this->logger = $logger;
    }

    public function send(string $command): void
    {
        $c = $this->config;
        if ($c === null) {
            return;
        }
        $vars = ['{{service}}' => $this->service, '{{cmd}}' => $command];
        $body = $c->body === null ? '' : strtr($c->body, $vars);
        $request = new Request($c->method, strtr($c->url, $vars), $c->headers, $body);
        $request->timeout = $c->timeout;
        $request->connectTimeout = min(5.0, $c->timeout);
        if ($c->username !== null) {
            $request->headers['Authorization'] = 'Basic ' . base64_encode($c->username . ':' . $c->password);
        }
        if ($body !== '' && $request->header('Content-Type') === null) {
            $request->headers['Content-Type'] = 'text/plain';
        }

        try {
            $response = $this->transport->send($request);
            if (!$response->isSuccessful()) {
                $this->logger->warning("Heartbeat returned HTTP {$response->status}", ['body' => substr($response->body, 0, 200)]);
            }
        } catch (HttpException $e) {
            $this->logger->warning('Heartbeat failed: ' . $e->getMessage());
        }
    }
}
