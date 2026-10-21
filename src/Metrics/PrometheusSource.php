<?php

namespace Wcore\Dnslb\Metrics;

use Wcore\Dnslb\Config\MetricSourceConfig;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Transport;

/**
 * Prometheus HTTP API（Grafana Cloud、Prometheus、VictoriaMetrics、Mimir 等兼容）。
 */
final class PrometheusSource implements MetricSourceInterface
{
    private MetricSourceConfig $config;
    private Transport $transport;

    public function __construct(MetricSourceConfig $config, Transport $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    public function queryByNode(string $query, string $nodeLabel = 'node_id'): array
    {
        $result = [];
        foreach ($this->query($query) as $series) {
            $node = $series['metric'][$nodeLabel] ?? null;
            if ($node === null || $node === '') {
                throw new MetricException("Query result has a series without the \"{$nodeLabel}\" label; aggregate with \"by ({$nodeLabel})\"");
            }
            if (array_key_exists($node, $result)) {
                throw new MetricException("Query returned more than one series for {$nodeLabel}=\"{$node}\"; aggregate with \"by ({$nodeLabel})\"");
            }
            $value = $series['value'][1] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $value = (float) $value;
            if (is_nan($value) || is_infinite($value)) {
                continue;
            }
            $result[$node] = $value;
        }
        return $result;
    }

    /**
     * @return array[] 结果中的 series 列表（resultType 必须是 vector）
     */
    public function query(string $query): array
    {
        $request = new Request('GET', $this->config->url . '/api/v1/query?' . http_build_query(['query' => $query], '', '&', PHP_QUERY_RFC3986));
        $request->timeout = $this->config->timeout;
        if ($this->config->username !== null) {
            $request->headers['Authorization'] = 'Basic ' . base64_encode($this->config->username . ':' . ($this->config->password ?? ''));
        } elseif ($this->config->password !== null) {
            $request->headers['Authorization'] = 'Bearer ' . $this->config->password;
        }

        try {
            $response = $this->transport->send($request);
        } catch (HttpException $e) {
            throw new MetricException("Metric source {$this->config->name}: {$e->getMessage()}", 0, $e);
        }
        $data = json_decode($response->body, true);
        if (!$response->isSuccessful() || !is_array($data) || ($data['status'] ?? '') !== 'success') {
            $error = is_array($data) ? ($data['error'] ?? '') : '';
            throw new MetricException(sprintf(
                'Metric source %s: query failed (HTTP %d)%s',
                $this->config->name,
                $response->status,
                $error !== '' ? ": {$error}" : ''
            ));
        }
        if (($data['data']['resultType'] ?? '') !== 'vector') {
            throw new MetricException("Metric source {$this->config->name}: expected an instant vector, got " . ($data['data']['resultType'] ?? 'nothing'));
        }
        return $data['data']['result'] ?? [];
    }
}
