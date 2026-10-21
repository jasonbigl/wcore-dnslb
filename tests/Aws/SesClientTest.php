<?php

namespace Wcore\Dnslb\Tests\Aws;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Aws\Credentials;
use Wcore\Dnslb\Aws\Ses\SesClient;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeTransport;

final class SesClientTest extends TestCase
{
    private FakeTransport $transport;
    private SesClient $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->client = new SesClient($this->transport, new Credentials('AKID', 'SECRET'), new FakeClock());
    }

    public function testSendEmail(): void
    {
        $this->transport->queue(new Response(200, ['Content-Type' => 'application/json'], '{"MessageId":"msg-1"}'));

        $id = $this->client->sendEmail('us-east-1', 'lb@example.com', ['ops@example.com'], '[dnslb] 扩容', "东京池扩容 1 台");

        self::assertSame('msg-1', $id);
        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('https://email.us-east-1.amazonaws.com/v2/email/outbound-emails', $request->url);
        self::assertStringContainsString('/us-east-1/ses/aws4_request', (string) $request->header('Authorization'));
        $payload = json_decode($request->body, true);
        self::assertSame('lb@example.com', $payload['FromEmailAddress']);
        self::assertSame(['ops@example.com'], $payload['Destination']['ToAddresses']);
        self::assertSame('[dnslb] 扩容', $payload['Content']['Simple']['Subject']['Data']);
        self::assertSame('东京池扩容 1 台', $payload['Content']['Simple']['Body']['Text']['Data']);
        self::assertArrayNotHasKey('Html', $payload['Content']['Simple']['Body']);
    }

    public function testErrorParsing(): void
    {
        $this->transport->queue(new Response(
            400,
            ['x-amzn-ErrorType' => 'MessageRejected:http://internal.amazon.com/coral/com.amazonaws.sesv2/', 'x-amzn-RequestId' => 'req-3'],
            '{"message":"Email address is not verified."}'
        ));

        try {
            $this->client->sendEmail('us-east-1', 'lb@example.com', ['ops@example.com'], 's', 'b');
            self::fail('Expected AwsException');
        } catch (AwsException $e) {
            self::assertSame('MessageRejected', $e->getErrorCode());
            self::assertStringContainsString('not verified', $e->getMessage());
            self::assertSame('req-3', $e->getRequestId());
        }
    }

    public function testThrottlingIsRetried(): void
    {
        $this->transport->queue(
            new Response(429, ['x-amzn-ErrorType' => 'TooManyRequestsException'], '{"message":"Rate exceeded"}'),
            new Response(200, [], '{"SendingEnabled":true,"ProductionAccessEnabled":false}')
        );

        $account = $this->client->getAccount('eu-west-1');

        self::assertTrue($account['SendingEnabled']);
        self::assertFalse($account['ProductionAccessEnabled']);
        self::assertCount(2, $this->transport->requests);
        self::assertSame('GET', $this->transport->lastRequest()->method);
        self::assertSame('', $this->transport->lastRequest()->body);
    }
}
