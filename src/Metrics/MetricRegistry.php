<?php

namespace Wcore\Dnslb\Metrics;

use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Http\Transport;

/**
 * 按配置创建指标数据源，并在同一次运行内缓存相同查询的结果（tick 中多个池共用同一个查询）。
 */
final class MetricRegistry
{
    private Config $config;
    private Transport $transport;
    /** @var array<string,MetricSourceInterface> */
    private array $sources = [];
    /** @var array<string,array<string,float>|MetricException> */
    private array $cache = [];

    public function __construct(Config $config, Transport $transport)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    public function get(string $name): MetricSourceInterface
    {
        if (!isset($this->sources[$name])) {
            $config = $this->config->metricSources[$name] ?? null;
            if ($config === null) {
                throw new \InvalidArgumentException("Unknown metric source: {$name}");
            }
            $this->sources[$name] = new PrometheusSource($config, $this->transport);
        }
        return $this->sources[$name];
    }

    /**
     * 替换数据源实现（测试用）。
     */
    public function set(string $name, MetricSourceInterface $source): void
    {
        $this->sources[$name] = $source;
    }

    /**
     * 按节点查询，同一查询在 clearCache() 之前只执行一次（失败结果也会缓存）。
     *
     * @return array<string,float>
     * @throws MetricException
     */
    public function queryByNode(string $source, string $query): array
    {
        $key = $source . "\0" . $query;
        if (!array_key_exists($key, $this->cache)) {
            try {
                $this->cache[$key] = $this->get($source)->queryByNode($query);
            } catch (MetricException $e) {
                $this->cache[$key] = $e;
            }
        }
        if ($this->cache[$key] instanceof MetricException) {
            throw $this->cache[$key];
        }
        return $this->cache[$key];
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
