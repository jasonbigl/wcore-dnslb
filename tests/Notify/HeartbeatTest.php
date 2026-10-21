<?php

namespace Wcore\Dnslb\Tests\Notify;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

final class HeartbeatTest extends TestCase
{
    public function testInfluxStyleHeartbeat(): void
    {
        $t = new TestApp(['heartbeat' => [
            'url' => 'https://heartbeat.test/api/v1/push/influx/write',
            'method' => 'POST',
            'auth' => ['123456', 'glc_write'],
            'body' => 'dnslb_heartbeat,service={{service}},cmd={{cmd}} up=1',
        ]]);

        $t->app->heartbeat()->send('tick');

        $request = $t->network->heartbeats[0];
        self::assertSame('POST', $request->method);
        self::assertSame('dnslb_heartbeat,service=web,cmd=tick up=1', $request->body);
        self::assertSame('Basic ' . base64_encode('123456:glc_write'), $request->header('Authorization'));
        self::assertSame('text/plain', $request->header('Content-Type'));
    }

    public function testPingStyleHeartbeatAndFailureIsOnlyLogged(): void
    {
        $t = new TestApp(['heartbeat' => ['url' => 'https://unreachable.test/ping/{{service}}-{{cmd}}']]);
        // FakeNetwork 把未知主机当作外部网站，这里让它不可达
        $t->network->canaryDown['v4'] = true;

        $t->app->heartbeat()->send('health');

        $request = $t->transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame('https://unreachable.test/ping/web-health', $request->url);
        self::assertSame('', $request->body);
        self::assertNotEmpty($t->logger->matching('warning: Heartbeat failed'));
    }

    public function testTickAndHealthSendHeartbeatsButNotInDryRun(): void
    {
        $t = new TestApp([
            'heartbeat' => ['url' => 'https://heartbeat.test/{{cmd}}'],
            'pools' => ['tokyo' => ['min' => 0], 'frankfurt' => ['min' => 0]],
        ]);
        $t->app->providers()->set('aws', new FakeProvider());
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $t->app->tickRunner()->run(false);
        $t->app->healthRunner()->run(10, true, false);
        $t->app->tickRunner()->run(true);
        $t->app->healthRunner()->run(10, true, true);

        self::assertSame(['https://heartbeat.test/tick', 'https://heartbeat.test/health'], array_map(static function ($r) {
            return $r->url;
        }, $t->network->heartbeats));
    }

    public function testTickWithFailedStepSendsNoHeartbeat(): void
    {
        $t = new TestApp([
            'heartbeat' => ['url' => 'https://heartbeat.test/{{cmd}}'],
            'pools' => ['tokyo' => ['min' => 0], 'frankfurt' => ['min' => 0]],
        ]);
        $t->app->providers()->set('aws', new FakeProvider());
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $t->network->route53->denied = true;

        self::assertTrue($t->app->tickRunner()->run(false), 'the tick still ran');
        self::assertSame([], $t->network->heartbeats);
        self::assertNotEmpty($t->logger->matching('not sending heartbeat'));

        $t->network->route53->denied = false;
        $t->app->tickRunner()->run(false);
        self::assertCount(1, $t->network->heartbeats);
    }
}
