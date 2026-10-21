<?php

namespace Wcore\Dnslb\Tests\Aws;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Aws\Credentials;
use Wcore\Dnslb\Aws\Route53\AliasTarget;
use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Aws\Route53\Route53Client;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeTransport;

final class Route53ClientTest extends TestCase
{
    private const NS = 'https://route53.amazonaws.com/doc/2013-04-01/';

    private FakeTransport $transport;
    private FakeClock $clock;
    private Route53Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->client = new Route53Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock);
    }

    public function testListRecordSetsByNameStopsAtOtherNames(): void
    {
        $this->transport->queue(new Response(200, [], $this->listResponse([
            $this->multiValueXml('tokyo.pool.app.example.com.', 'A', 'web-tokyo-aaaaaa', '1.2.3.4'),
            $this->multiValueXml('tokyo.pool.app.example.com.', 'AAAA', 'web-tokyo-aaaaaa', '2001:db8:0:0::1'),
            $this->multiValueXml('zzz.pool.app.example.com.', 'A', 'other', '9.9.9.9'),
        ], true, 'zzz.pool.app.example.com.', 'A')));

        $records = $this->client->listRecordSetsByName('/hostedzone/Z123', 'Tokyo.pool.app.example.com.');

        self::assertCount(2, $records);
        self::assertSame('tokyo.pool.app.example.com', $records[0]->name);
        self::assertSame('A', $records[0]->type);
        self::assertSame('web-tokyo-aaaaaa', $records[0]->setIdentifier);
        self::assertTrue($records[0]->multiValueAnswer);
        self::assertSame(60, $records[0]->ttl);
        self::assertSame(['1.2.3.4'], $records[0]->values);
        self::assertSame('AAAA', $records[1]->type);

        $request = $this->transport->lastRequest();
        self::assertSame('GET', $request->method);
        self::assertSame(
            'https://route53.amazonaws.com/2013-04-01/hostedzone/Z123/rrset?name=tokyo.pool.app.example.com.&maxitems=300',
            $request->url
        );
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKID/', (string) $request->header('Authorization'));
    }

    public function testListRecordSetsByNameFollowsPagination(): void
    {
        $this->transport->queue(
            new Response(200, [], $this->listResponse([
                $this->multiValueXml('app.example.com.', 'A', 'n1', '1.1.1.1'),
            ], true, 'app.example.com.', 'A', 'n2')),
            new Response(200, [], $this->listResponse([
                $this->multiValueXml('app.example.com.', 'A', 'n2', '2.2.2.2'),
            ], false))
        );

        $records = $this->client->listRecordSetsByName('Z123', 'app.example.com');

        self::assertSame(['n1', 'n2'], array_map(static function (RecordSet $r) {
            return $r->setIdentifier;
        }, $records));
        self::assertStringContainsString('identifier=n2', $this->transport->lastRequest()->url);
        self::assertStringContainsString('type=A', $this->transport->lastRequest()->url);
    }

    public function testListsWildcardNamesUsingEscapes(): void
    {
        $this->transport->queue(new Response(200, [], $this->listResponse([
            $this->multiValueXml('\\052.example.net.', 'A', 'n1', '1.1.1.1'),
            $this->multiValueXml('api.example.net.', 'A', 'n2', '2.2.2.2'),
        ], false)));

        $records = $this->client->listRecordSetsByName('Z1', '*.example.net');

        self::assertCount(1, $records);
        self::assertSame('*.example.net', $records[0]->name);
        self::assertSame('*.example.net|A|n1', $records[0]->key());
        parse_str((string) parse_url($this->transport->lastRequest()->url, PHP_URL_QUERY), $query);
        self::assertSame('\\052.example.net.', $query['name']);
    }

    public function testParsesLatencyAliasRecord(): void
    {
        $xml = '<ResourceRecordSet><Name>app.example.com.</Name><Type>A</Type><SetIdentifier>dnslb-tokyo</SetIdentifier>'
            . '<Region>ap-northeast-1</Region><AliasTarget><HostedZoneId>Z123</HostedZoneId>'
            . '<DNSName>tokyo.pool.app.example.com.</DNSName><EvaluateTargetHealth>true</EvaluateTargetHealth></AliasTarget>'
            . '</ResourceRecordSet>';
        $this->transport->queue(new Response(200, [], $this->listResponse([$xml], false)));

        [$record] = $this->client->listRecordSetsByName('Z123', 'app.example.com');

        self::assertSame('ap-northeast-1', $record->region);
        self::assertNotNull($record->aliasTarget);
        self::assertSame('tokyo.pool.app.example.com', $record->aliasTarget->dnsName);
        self::assertTrue($record->aliasTarget->evaluateTargetHealth);
        self::assertNull($record->ttl);
    }

    public function testChangeRecordSetsBuildsSchemaOrderedXml(): void
    {
        $this->transport->queue(new Response(200, [], '<?xml version="1.0"?><ChangeResourceRecordSetsResponse xmlns="' . self::NS . '">'
            . '<ChangeInfo><Id>/change/C2682N5HXP0BZ4</Id><Status>PENDING</Status></ChangeInfo></ChangeResourceRecordSetsResponse>'));

        $changeId = $this->client->changeRecordSets('Z123', [
            Change::create(RecordSet::multiValue('tokyo.pool.app.example.com', 'A', 'web-tokyo-aaaaaa', 60, ['1.2.3.4'])),
            Change::upsert(RecordSet::latencyAlias(
                'app.example.com',
                'A',
                'dnslb-tokyo',
                'ap-northeast-1',
                new AliasTarget('Z123', 'tokyo.pool.app.example.com', true)
            )),
            Change::delete(RecordSet::weighted('fra.pool.app.example.com', 'AAAA', 'web-fra-bbbbbb', 10, 60, ['2001:db8::2'])),
        ], 'dnslb <test>');

        self::assertSame('C2682N5HXP0BZ4', $changeId);
        $request = $this->transport->lastRequest();
        self::assertSame('POST', $request->method);
        self::assertSame('https://route53.amazonaws.com/2013-04-01/hostedzone/Z123/rrset', $request->url);

        $body = $request->body;
        self::assertStringContainsString('<Comment>dnslb &lt;test&gt;</Comment>', $body);
        self::assertStringContainsString(
            '<Change><Action>CREATE</Action><ResourceRecordSet><Name>tokyo.pool.app.example.com</Name><Type>A</Type>'
            . '<SetIdentifier>web-tokyo-aaaaaa</SetIdentifier><MultiValueAnswer>true</MultiValueAnswer><TTL>60</TTL>'
            . '<ResourceRecords><ResourceRecord><Value>1.2.3.4</Value></ResourceRecord></ResourceRecords></ResourceRecordSet></Change>',
            $body
        );
        self::assertStringContainsString(
            '<SetIdentifier>dnslb-tokyo</SetIdentifier><Region>ap-northeast-1</Region><AliasTarget><HostedZoneId>Z123</HostedZoneId>'
            . '<DNSName>tokyo.pool.app.example.com</DNSName><EvaluateTargetHealth>true</EvaluateTargetHealth></AliasTarget>',
            $body
        );
        self::assertStringContainsString('<SetIdentifier>web-fra-bbbbbb</SetIdentifier><Weight>10</Weight><TTL>60</TTL>', $body);

        $parsed = simplexml_load_string($body);
        self::assertNotFalse($parsed, 'request body must be well-formed XML');
        self::assertCount(3, $parsed->ChangeBatch->Changes->Change);
    }

    public function testInvalidChangeBatchIsNotRetried(): void
    {
        $this->transport->queue(new Response(400, [], '<?xml version="1.0"?><InvalidChangeBatch xmlns="' . self::NS . '">'
            . '<Messages><Message>Tried to delete resource record set but it was not found</Message></Messages>'
            . '<RequestId>req-1</RequestId></InvalidChangeBatch>'));

        try {
            $this->client->changeRecordSets('Z123', [Change::delete(RecordSet::multiValue('a.example.com', 'A', 'x', 60, ['1.2.3.4']))]);
            self::fail('Expected AwsException');
        } catch (AwsException $e) {
            self::assertSame('InvalidChangeBatch', $e->getErrorCode());
            self::assertStringContainsString('not found', $e->getMessage());
            self::assertSame('req-1', $e->getRequestId());
            self::assertFalse($e->isRetryable());
        }
        self::assertCount(1, $this->transport->requests);
    }

    public function testThrottlingAndNetworkErrorsAreRetriedWithBackoff(): void
    {
        $this->transport->queue(
            new Response(400, [], '<?xml version="1.0"?><ErrorResponse xmlns="' . self::NS . '"><Error><Type>Sender</Type>'
                . '<Code>Throttling</Code><Message>Rate exceeded</Message></Error><RequestId>r</RequestId></ErrorResponse>'),
            new HttpException('connection reset'),
            new Response(200, [], '<GetChangeResponse xmlns="' . self::NS . '"><ChangeInfo><Id>/change/C1</Id><Status>INSYNC</Status></ChangeInfo></GetChangeResponse>')
        );

        self::assertSame('INSYNC', $this->client->getChange('/change/C1'));
        self::assertCount(3, $this->transport->requests);
        self::assertCount(2, $this->clock->sleeps);
        self::assertGreaterThanOrEqual(100000, $this->clock->sleeps[0]);
        self::assertLessThanOrEqual(200000, $this->clock->sleeps[0]);
        self::assertGreaterThanOrEqual(200000, $this->clock->sleeps[1]);
        self::assertLessThanOrEqual(400000, $this->clock->sleeps[1]);
    }

    public function testGivesUpAfterMaxAttempts(): void
    {
        $client = new Route53Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock, 2);
        $this->transport->queue(new Response(503, [], ''), new Response(503, [], ''));

        $this->expectException(AwsException::class);
        try {
            $client->getChange('C1');
        } finally {
            self::assertCount(2, $this->transport->requests);
        }
    }

    public function testGetHostedZone(): void
    {
        $this->transport->queue(new Response(200, [], '<GetHostedZoneResponse xmlns="' . self::NS . '"><HostedZone>'
            . '<Id>/hostedzone/Z123</Id><Name>example.com.</Name></HostedZone></GetHostedZoneResponse>'));

        self::assertSame(['id' => 'Z123', 'name' => 'example.com'], $this->client->getHostedZone('Z123'));
    }

    public function testRecordSetComparisonNormalizesValues(): void
    {
        $a = RecordSet::multiValue('X.example.com.', 'AAAA', 'n1', 60, ['2001:DB8:0:0:0:0:0:1']);
        $b = RecordSet::multiValue('x.example.com', 'AAAA', 'n1', 60, ['2001:db8::1']);
        self::assertTrue($a->sameAs($b));

        $c = RecordSet::multiValue('x.example.com', 'AAAA', 'n1', 300, ['2001:db8::1']);
        self::assertFalse($a->sameAs($c));
    }

    private function listResponse(array $records, bool $truncated, ?string $nextName = null, ?string $nextType = null, ?string $nextId = null): string
    {
        $xml = '<?xml version="1.0"?><ListResourceRecordSetsResponse xmlns="' . self::NS . '"><ResourceRecordSets>'
            . implode('', $records) . '</ResourceRecordSets><IsTruncated>' . ($truncated ? 'true' : 'false') . '</IsTruncated>';
        if ($nextName !== null) {
            $xml .= "<NextRecordName>{$nextName}</NextRecordName><NextRecordType>{$nextType}</NextRecordType>";
        }
        if ($nextId !== null) {
            $xml .= "<NextRecordIdentifier>{$nextId}</NextRecordIdentifier>";
        }
        return $xml . '<MaxItems>300</MaxItems></ListResourceRecordSetsResponse>';
    }

    private function multiValueXml(string $name, string $type, string $id, string $value): string
    {
        return "<ResourceRecordSet><Name>{$name}</Name><Type>{$type}</Type><SetIdentifier>{$id}</SetIdentifier>"
            . "<MultiValueAnswer>true</MultiValueAnswer><TTL>60</TTL><ResourceRecords><ResourceRecord><Value>{$value}</Value>"
            . '</ResourceRecord></ResourceRecords></ResourceRecordSet>';
    }
}
