<?php

namespace Wcore\Dnslb\Health;

use Wcore\Dnslb\Config\HealthCheckConfig;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\State\Node;

/**
 * 并行探测节点健康检查路径和金丝雀地址。
 *
 * 节点请求用健康检查域名发出，再通过 CURLOPT_RESOLVE 固定到节点 IP，
 * 这样 SNI、Host 头都与真实访问一致，并且可以校验证书。
 */
final class Prober
{
    private Transport $transport;
    private HealthCheckConfig $config;

    public function __construct(Transport $transport, HealthCheckConfig $config)
    {
        $this->transport = $transport;
        $this->config = $config;
    }

    /**
     * @param ProbeTarget[] $targets
     * @param string[] $families 需要检查金丝雀的协议族
     */
    public function run(array $targets, array $families): ProbeRound
    {
        $requests = [];
        foreach ($families as $family) {
            foreach ($this->config->canaryUrls as $i => $url) {
                $requests["canary/{$family}/{$i}"] = $this->canaryRequest($url, $family);
            }
        }
        foreach ($targets as $target) {
            $requests['node/' . $target->key()] = $this->nodeRequest($target);
        }
        if ($requests === []) {
            return new ProbeRound([], []);
        }

        $responses = $this->transport->sendAll($requests, $this->config->concurrency);

        $canaryOk = array_fill_keys($families, false);
        foreach ($families as $family) {
            foreach ($this->config->canaryUrls as $i => $_) {
                // 金丝雀只验证网络可达，收到任何 HTTP 响应都算成功
                if ($responses["canary/{$family}/{$i}"] instanceof Response) {
                    $canaryOk[$family] = true;
                    break;
                }
            }
        }

        $results = [];
        foreach ($targets as $target) {
            $results[$target->key()] = $this->evaluate($responses['node/' . $target->key()]);
        }
        return new ProbeRound($results, $canaryOk);
    }

    public function nodeRequest(ProbeTarget $target): Request
    {
        $c = $this->config;
        $request = new Request('GET', "{$c->scheme}://{$c->host}:{$c->port}{$c->path}");
        $address = $target->family === Node::FAMILY_V6 ? "[{$target->ip}]" : $target->ip;
        $request->resolve = ["{$c->host}:{$c->port}:{$address}"];
        $request->ipVersion = $target->family;
        $request->verifyTls = $c->verifyTls;
        $request->timeout = $c->timeout;
        $request->connectTimeout = $c->connectTimeout;
        return $request;
    }

    private function canaryRequest(string $url, string $family): Request
    {
        $request = new Request('GET', $url);
        $request->ipVersion = $family;
        $request->timeout = $this->config->timeout;
        $request->connectTimeout = $this->config->connectTimeout;
        return $request;
    }

    /**
     * @param Response|HttpException $response
     */
    private function evaluate($response): ProbeResult
    {
        if ($response instanceof HttpException) {
            return new ProbeResult(false, $response->getMessage(), null, 0);
        }
        $latency = (int) round($response->totalTime * 1000);
        $c = $this->config;
        if ($response->status < $c->expectStatusMin || $response->status > $c->expectStatusMax) {
            return new ProbeResult(false, "unexpected HTTP status {$response->status}", $response->status, $latency);
        }
        if ($c->expectBody !== null && strpos($response->body, $c->expectBody) === false) {
            return new ProbeResult(false, 'response body does not contain expected text', $response->status, $latency);
        }
        return new ProbeResult(true, null, $response->status, $latency);
    }
}
