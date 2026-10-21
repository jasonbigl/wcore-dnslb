<?php

namespace Wcore\Dnslb\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\Linode\LinodeClient;
use Wcore\Dnslb\Provider\Linode\LinodeProvider;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeTransport;

final class LinodeProviderTest extends TestCase
{
    private FakeTransport $transport;
    private FakeClock $clock;
    private LinodeProvider $provider;
    private LaunchSpec $spec;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->provider = new LinodeProvider('linode', new LinodeClient($this->transport, 'tok', 'https://api.linode.com/v4', $this->clock));
        $this->spec = new LaunchSpec();
        $this->spec->index = 0;
        $this->spec->provider = 'linode';
        $this->spec->driver = 'linode';
        $this->spec->region = 'ap-northeast';
        $this->spec->weight = 10;
        $this->spec->options = [
            'type' => 'g6-dedicated-2',
            'image' => 'linode/ubuntu24.04',
            'firewall_id' => 42,
            'authorized_keys' => ['ssh-ed25519 AAAA ops'],
            'private_ip' => false,
            'tags' => ['team-web'],
        ];
    }

    public function testCreate(): void
    {
        $this->transport->queue(new Response(200, [], (string) json_encode(self::instance(['status' => 'provisioning']))));

        $instance = $this->provider->create($this->spec, 'web-tokyo-abc123', "#cloud-config\n", ['dnslb:service' => 'web', 'dnslb:pool' => 'tokyo']);

        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('https://api.linode.com/v4/linode/instances', $request->url);
        self::assertSame('Bearer tok', $request->header('Authorization'));
        $body = json_decode($request->body, true);
        self::assertSame('web-tokyo-abc123', $body['label']);
        self::assertSame('ap-northeast', $body['region']);
        self::assertSame('g6-dedicated-2', $body['type']);
        self::assertSame('linode/ubuntu24.04', $body['image']);
        self::assertSame(['dnslb:service=web', 'dnslb:pool=tokyo', 'team-web'], $body['tags']);
        self::assertSame("#cloud-config\n", base64_decode($body['metadata']['user_data']));
        self::assertSame(['ssh-ed25519 AAAA ops'], $body['authorized_keys']);
        self::assertSame(42, $body['firewall_id']);
        self::assertTrue($body['booted']);
        self::assertGreaterThanOrEqual(32, strlen($body['root_pass']));
        self::assertArrayNotHasKey('private_ip', $body);

        self::assertSame('12345', $instance->id);
        self::assertSame(Instance::PENDING, $instance->status);
        self::assertSame('web-tokyo-abc123', $instance->nodeId);
    }

    public function testParsesInstance(): void
    {
        $instance = $this->provider->toInstance(self::instance([
            'ipv4' => ['192.168.140.5', '203.0.113.5'],
            'ipv6' => '2600:3c01::f03c:91ff:fe24:3a2f/128',
            'status' => 'running',
            'tags' => ['dnslb:service=web', 'dnslb:pool=tokyo', 'team-web'],
            'created' => '2026-10-19T08:00:00',
        ]));

        self::assertSame('203.0.113.5', $instance->ipv4, 'private address is skipped');
        self::assertSame('2600:3c01::f03c:91ff:fe24:3a2f', $instance->ipv6);
        self::assertSame(Instance::RUNNING, $instance->status);
        self::assertSame(['dnslb:service' => 'web', 'dnslb:pool' => 'tokyo', 'team-web' => ''], $instance->tags);
        self::assertSame(gmmktime(8, 0, 0, 10, 19, 2026), $instance->createdAt);
        self::assertSame(Instance::STOPPED, $this->provider->toInstance(self::instance(['status' => 'offline']))->status);
        self::assertSame(Instance::GONE, $this->provider->toInstance(self::instance(['status' => 'deleting']))->status);
    }

    public function testRetriedCreateAdoptsInstanceCreatedByTimedOutRequest(): void
    {
        $this->transport->queue(
            new HttpException('Operation timed out'),
            new Response(400, [], '{"errors":[{"field":"label","reason":"Label must be unique among your Linodes"}]}'),
            new Response(200, [], (string) json_encode(['data' => [self::instance()], 'page' => 1, 'pages' => 1]))
        );

        $instance = $this->provider->create($this->spec, 'web-tokyo-abc123', '', []);

        self::assertSame('12345', $instance->id);
        self::assertSame('{"label":"web-tokyo-abc123"}', $this->transport->lastRequest()->header('X-Filter'));
    }

    public function testOtherErrorsAreRaised(): void
    {
        $this->transport->queue(new Response(400, [], '{"errors":[{"field":"type","reason":"A valid plan type by that ID was not found"}]}'));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('type: A valid plan type by that ID was not found');
        $this->provider->create($this->spec, 'web-tokyo-abc123', '', []);
    }

    public function testServerErrorsOnCreateLeaveTheOutcomeUnknown(): void
    {
        $this->transport->queue(
            new HttpException('Operation timed out'),
            new Response(429, [], '{"errors":[{"reason":"Too many requests"}]}'),
            new Response(503, [], ''),
            new Response(503, [], '')
        );

        $this->expectException(OutcomeUnknownException::class);
        $this->provider->create($this->spec, 'web-tokyo-abc123', '', []);
    }

    public function testRateLimitHonoursRetryAfter(): void
    {
        $this->transport->queue(
            new Response(429, ['Retry-After' => '2'], '{"errors":[{"reason":"Too many requests"}]}'),
            new Response(200, [], (string) json_encode(self::instance()))
        );

        self::assertNotNull($this->provider->get('ap-northeast', '12345'));
        self::assertSame([2000000], $this->clock->sleeps);
    }

    public function testGetMissingInstanceReturnsNull(): void
    {
        $this->transport->queue(new Response(404, [], '{"errors":[{"reason":"Not found"}]}'));

        self::assertNull($this->provider->get('ap-northeast', '999'));
    }

    public function testListManagedFollowsPagesAndFiltersByServiceTag(): void
    {
        $this->transport->queue(
            new Response(200, [], (string) json_encode(['data' => [self::instance(['id' => 1])], 'page' => 1, 'pages' => 2])),
            new Response(200, [], (string) json_encode(['data' => [self::instance(['id' => 2])], 'page' => 2, 'pages' => 2]))
        );

        $instances = $this->provider->listManaged('web', ['ap-northeast']);

        self::assertSame(['1', '2'], array_map(static function (Instance $i) {
            return $i->id;
        }, $instances));
        self::assertSame('{"tags":"dnslb:service=web"}', $this->transport->requests[0]->header('X-Filter'));
        self::assertStringContainsString('page=2', $this->transport->requests[1]->url);
    }

    public function testTerminateIsIdempotentAndRetriesServerErrors(): void
    {
        $this->transport->queue(new Response(500, [], ''), new Response(200, [], '{}'), new Response(404, [], ''));

        $this->provider->terminate('ap-northeast', '12345');
        $this->provider->terminate('ap-northeast', '12345');

        self::assertCount(3, $this->transport->requests);
        self::assertSame('DELETE', $this->transport->requests[0]->method);
    }

    private static function instance(array $overrides = []): array
    {
        return $overrides + [
            'id' => 12345,
            'label' => 'web-tokyo-abc123',
            'region' => 'ap-northeast',
            'status' => 'running',
            'ipv4' => ['203.0.113.5'],
            'ipv6' => '2600:3c01::1/128',
            'tags' => ['dnslb:service=web'],
            'created' => '2026-10-19T08:00:00',
        ];
    }
}
