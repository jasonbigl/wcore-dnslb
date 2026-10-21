<?php

namespace Wcore\Dnslb\Tests\Metrics;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Config\MetricSourceConfig;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Metrics\MetricException;
use Wcore\Dnslb\Metrics\PrometheusSource;
use Wcore\Dnslb\Tests\Support\FakeTransport;

final class PrometheusSourceTest extends TestCase
{
    private FakeTransport $transport;
    private PrometheusSource $source;

    protected function setUp(): void
    {
        $config = new MetricSourceConfig();
        $config->name = 'grafana';
        $config->driver = 'prometheus';
        $config->url = 'https://prometheus-prod.grafana.net/api/prom';
        $config->username = '123456';
        $config->password = 'glc_token';
        $config->timeout = 15.0;
        $this->transport = new FakeTransport();
        $this->source = new PrometheusSource($config, $this->transport);
    }

    public function testQueryByNode(): void
    {
        $this->transport->queue(new Response(200, [], (string) json_encode(['status' => 'success', 'data' => [
            'resultType' => 'vector',
            'result' => [
                ['metric' => ['node_id' => 'web-tokyo-a'], 'value' => [1700000000, '42.5']],
                ['metric' => ['node_id' => 'web-tokyo-b'], 'value' => [1700000000, '7']],
                ['metric' => ['node_id' => 'web-tokyo-c'], 'value' => [1700000000, 'NaN']],
            ],
        ]])));

        $values = $this->source->queryByNode('avg by (node_id) (up)');

        self::assertSame(['web-tokyo-a' => 42.5, 'web-tokyo-b' => 7.0], $values);
        $request = $this->transport->lastRequest();
        self::assertSame('https://prometheus-prod.grafana.net/api/prom/api/v1/query?query=avg%20by%20%28node_id%29%20%28up%29', $request->url);
        self::assertSame('Basic ' . base64_encode('123456:glc_token'), $request->header('Authorization'));
        self::assertSame(15.0, $request->timeout);
    }

    public function testRejectsUnaggregatedResults(): void
    {
        $this->transport->queue(new Response(200, [], (string) json_encode(['status' => 'success', 'data' => [
            'resultType' => 'vector',
            'result' => [
                ['metric' => ['node_id' => 'a', 'cpu' => '0'], 'value' => [0, '1']],
                ['metric' => ['node_id' => 'a', 'cpu' => '1'], 'value' => [0, '2']],
            ],
        ]])));

        $this->expectException(MetricException::class);
        $this->expectExceptionMessage('more than one series for node_id="a"');
        $this->source->queryByNode('x');
    }

    public function testRejectsSeriesWithoutNodeLabel(): void
    {
        $this->transport->queue(new Response(200, [], (string) json_encode(['status' => 'success', 'data' => [
            'resultType' => 'vector',
            'result' => [['metric' => [], 'value' => [0, '1']]],
        ]])));

        $this->expectExceptionMessage('without the "node_id" label');
        $this->source->queryByNode('x');
    }

    public function testErrors(): void
    {
        $this->transport->queue(
            new Response(400, [], '{"status":"error","errorType":"bad_data","error":"parse error at char 5"}'),
            new HttpException('timeout'),
            new Response(200, [], '{"status":"success","data":{"resultType":"matrix","result":[]}}')
        );

        foreach (['parse error at char 5', 'timeout', 'expected an instant vector'] as $expected) {
            try {
                $this->source->queryByNode('x');
                self::fail('Expected MetricException');
            } catch (MetricException $e) {
                self::assertStringContainsString($expected, $e->getMessage());
            }
        }
    }
}
