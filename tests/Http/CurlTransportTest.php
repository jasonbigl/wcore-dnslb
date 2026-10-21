<?php

namespace Wcore\Dnslb\Tests\Http;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Http\CurlTransport;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * 用 PHP 内置服务器做真实的 HTTP 往返测试。
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $server;
    private static int $port;
    private static int $closedPort;

    private CurlTransport $transport;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        self::$closedPort = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fixtures/router.php'];
        $env = ['PHP_CLI_SERVER_WORKERS' => '32'] + getenv();
        self::$server = proc_open(
            $command,
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            $env
        );
        if (!self::waitForPort('127.0.0.1', self::$port)) {
            self::fail('Built-in PHP server did not start');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        $this->transport = new CurlTransport();
    }

    public function testGet(): void
    {
        $response = $this->transport->send(new Request('GET', $this->url('/ok')));

        self::assertSame(200, $response->status);
        self::assertSame('ok', $response->body);
        self::assertGreaterThan(0, $response->totalTime);
    }

    public function testPostWithHeadersAndBody(): void
    {
        $response = $this->transport->send(new Request('POST', $this->url('/echo'), ['X-Custom' => 'hello'], 'payload'));
        $data = $response->json();

        self::assertSame('POST', $data['method']);
        self::assertSame('hello', $data['custom']);
        self::assertSame('payload', $data['body']);
        self::assertSame('application/json', $response->header('Content-Type'));
        self::assertSame('a, b', $response->header('x-multi'));
    }

    public function testErrorStatusIsReturnedNotThrown(): void
    {
        $response = $this->transport->send(new Request('GET', $this->url('/status?code=503')));

        self::assertSame(503, $response->status);
        self::assertFalse($response->isSuccessful());
    }

    public function testConnectionRefusedThrows(): void
    {
        $this->expectException(HttpException::class);
        $this->transport->send(new Request('GET', 'http://127.0.0.1:' . self::$closedPort . '/'));
    }

    public function testTimeoutThrows(): void
    {
        // 服务端只多睡 0.5 秒：内置服务器的工作进程会继续处理被放弃的请求，睡太久会拖慢后面的并行测试
        $request = new Request('GET', $this->url('/sleep?ms=800'));
        $request->timeout = 0.3;

        $start = microtime(true);
        try {
            $this->transport->send($request);
            self::fail('Expected HttpException');
        } catch (HttpException $e) {
            self::assertStringContainsString('/sleep', $e->getMessage());
            self::assertStringNotContainsString('ms=800', $e->getMessage(), 'query string must not leak into errors');
        }
        self::assertLessThan(1.5, microtime(true) - $start);
    }

    public function testResolvePinsHostnameToAddress(): void
    {
        $request = new Request('GET', 'http://app.dnslb.test:' . self::$port . '/echo');
        $request->resolve = ['app.dnslb.test:' . self::$port . ':127.0.0.1'];
        $request->ipVersion = 'v4';

        $data = $this->transport->send($request)->json();

        self::assertSame('app.dnslb.test:' . self::$port, $data['host']);
    }

    public function testPinnedRequestsNeverReuseAnotherAddressesConnection(): void
    {
        // 两台 keep-alive 服务器监听同一端口的不同地址，相当于主机名相同的两个节点
        $port = self::freePort();
        $servers = [];
        try {
            foreach (['127.0.0.1', '127.0.0.2'] as $ip) {
                $servers[] = proc_open(
                    [PHP_BINARY, __DIR__ . '/fixtures/keepalive.php', $ip, (string) $port],
                    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                    $pipes
                );
                if (!self::waitForPort($ip, $port)) {
                    self::markTestSkipped("Cannot listen on {$ip}");
                }
            }
            $requests = [];
            foreach (['a' => '127.0.0.1', 'b' => '127.0.0.2'] as $key => $ip) {
                $requests[$key] = new Request('GET', "http://node.dnslb.test:{$port}/");
                $requests[$key]->resolve = ["node.dnslb.test:{$port}:{$ip}"];
            }

            // 并发数为 1：第二个请求在第一个完成后才开始，正是可能复用连接的时候
            $results = $this->transport->sendAll($requests, 1);

            self::assertSame('127.0.0.1', $results['a']->body);
            self::assertSame('127.0.0.2', $results['b']->body);
        } finally {
            foreach ($servers as $server) {
                proc_terminate($server);
                proc_close($server);
            }
        }
    }

    public function testSendAllRunsInParallelAndKeepsKeys(): void
    {
        $requests = [];
        for ($i = 0; $i < 6; $i++) {
            $requests["node-{$i}"] = new Request('GET', $this->url('/sleep?ms=400'));
        }
        $requests['refused'] = new Request('GET', 'http://127.0.0.1:' . self::$closedPort . '/');
        $requests['missing'] = new Request('GET', $this->url('/nope'));
        $requests['echo'] = new Request('GET', $this->url('/echo'));

        $start = microtime(true);
        $results = $this->transport->sendAll($requests, 8);
        $elapsed = microtime(true) - $start;

        self::assertSame(array_keys($requests), array_keys($results));
        for ($i = 0; $i < 6; $i++) {
            self::assertInstanceOf(Response::class, $results["node-{$i}"]);
            self::assertSame('slept', $results["node-{$i}"]->body);
        }
        self::assertInstanceOf(HttpException::class, $results['refused']);
        self::assertSame(404, $results['missing']->status);
        self::assertSame('a, b', $results['echo']->header('X-Multi'), 'each response must keep its own headers');
        self::assertLessThan(1.6, $elapsed, 'requests should run concurrently, not one after another (6 x 0.4s)');
    }

    public function testSendAllRespectsConcurrencyLimit(): void
    {
        $requests = [];
        for ($i = 0; $i < 4; $i++) {
            $requests[] = new Request('GET', $this->url('/sleep?ms=300'));
        }

        $start = microtime(true);
        $results = $this->transport->sendAll($requests, 2);
        $elapsed = microtime(true) - $start;

        self::assertCount(4, $results);
        self::assertGreaterThanOrEqual(0.55, $elapsed, 'with concurrency 2, four 0.3s requests need two rounds');
    }

    private function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    private static function waitForPort(string $ip, int $port): bool
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen($ip, $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                return true;
            }
            usleep(50000);
        }
        return false;
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr((string) strrchr((string) $name, ':'), 1);
    }
}
