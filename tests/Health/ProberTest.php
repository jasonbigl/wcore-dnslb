<?php

namespace Wcore\Dnslb\Tests\Health;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Health\Prober;
use Wcore\Dnslb\Health\ProbeTarget;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Tests\Support\FakeTransport;
use Wcore\Dnslb\Tests\Support\TestApp;

final class ProberTest extends TestCase
{
    public function testBuildsPinnedRequests(): void
    {
        $config = TestApp::config(['health_check' => ['port' => 8443, 'path' => '/healthz', 'verify_tls' => false, 'timeout' => 4]]);
        $prober = new Prober(new FakeTransport(), $config->healthCheck);

        $v4 = $prober->nodeRequest(new ProbeTarget('n1', 'v4', '203.0.113.5'));
        self::assertSame('https://app.example.com:8443/healthz', $v4->url);
        self::assertSame(['app.example.com:8443:203.0.113.5'], $v4->resolve);
        self::assertSame('v4', $v4->ipVersion);
        self::assertFalse($v4->verifyTls);
        self::assertSame(4.0, $v4->timeout);
        self::assertSame(3.0, $v4->connectTimeout);

        $v6 = $prober->nodeRequest(new ProbeTarget('n1', 'v6', '2001:db8::5'));
        self::assertSame(['app.example.com:8443:[2001:db8::5]'], $v6->resolve);
        self::assertSame('v6', $v6->ipVersion);
    }

    public function testEvaluatesResponses(): void
    {
        $config = TestApp::config(['health_check' => ['expect_status' => [200, 204], 'expect_body' => 'OK']]);
        $transport = (new FakeTransport())->handle(static function (Request $request) {
            if ($request->resolve === []) {
                return new Response(200);
            }
            $ip = explode(':', $request->resolve[0], 3)[2];
            switch ($ip) {
                case '10.0.0.1':
                    return new Response(200, [], 'status: OK', 0.0425);
                case '10.0.0.2':
                    return new Response(503, [], 'OK');
                case '10.0.0.3':
                    return new Response(200, [], 'starting');
                default:
                    return new HttpException('connect timeout');
            }
        });
        $prober = new Prober($transport, $config->healthCheck);

        $targets = [];
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3', '10.0.0.4'] as $i => $ip) {
            $targets[] = new ProbeTarget("n{$i}", 'v4', $ip);
        }
        $round = $prober->run($targets, ['v4']);

        self::assertTrue($round->results['n0/v4']->ok);
        self::assertSame(43, $round->results['n0/v4']->latencyMs);
        self::assertFalse($round->results['n1/v4']->ok);
        self::assertSame('unexpected HTTP status 503', $round->results['n1/v4']->error);
        self::assertFalse($round->results['n2/v4']->ok);
        self::assertStringContainsString('expected text', (string) $round->results['n2/v4']->error);
        self::assertFalse($round->results['n3/v4']->ok);
        self::assertSame('connect timeout', $round->results['n3/v4']->error);
        self::assertTrue($round->canaryOk['v4']);
    }

    public function testCanaryNeedsOnlyOneReachableUrl(): void
    {
        $config = TestApp::config(['health_check' => ['canary_urls' => ['https://a.test/', 'https://b.test/']]]);
        $transport = (new FakeTransport())->handle(static function (Request $request) {
            if ($request->ipVersion === 'v6') {
                return new HttpException('no route to host');
            }
            // 任何 HTTP 响应都说明网络可达
            return strpos($request->url, 'a.test') !== false ? new HttpException('dns failure') : new Response(500);
        });

        $round = (new Prober($transport, $config->healthCheck))->run([], ['v4', 'v6']);

        self::assertSame(['v4' => true, 'v6' => false], $round->canaryOk);
        self::assertCount(4, $transport->requests);
    }

    public function testNoRequestsWhenNothingToProbe(): void
    {
        $transport = new FakeTransport();
        $round = (new Prober($transport, TestApp::config()->healthCheck))->run([], []);

        self::assertSame([], $round->results);
        self::assertSame([], $transport->requests);
    }
}
