<?php

namespace Wcore\Dnslb\Tests\Aws;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Aws\Credentials;
use Wcore\Dnslb\Aws\Ec2\Ec2Client;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeTransport;

final class Ec2ClientTest extends TestCase
{
    private const NS = 'http://ec2.amazonaws.com/doc/2016-11-15/';

    private FakeTransport $transport;
    private FakeClock $clock;
    private Ec2Client $client;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->client = new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock);
    }

    public function testFlatten(): void
    {
        self::assertSame([
            'ImageId' => 'ami-1',
            'NetworkInterface.1.DeviceIndex' => '0',
            'NetworkInterface.1.AssociatePublicIpAddress' => 'true',
            'NetworkInterface.1.SecurityGroupId.1' => 'sg-1',
            'NetworkInterface.1.SecurityGroupId.2' => 'sg-2',
            'TagSpecification.1.ResourceType' => 'instance',
            'TagSpecification.1.Tag.1.Key' => 'Name',
            'TagSpecification.1.Tag.1.Value' => 'web-tokyo-aaaaaa',
        ], Ec2Client::flatten([
            'ImageId' => 'ami-1',
            'KeyName' => null,
            'NetworkInterface' => [[
                'DeviceIndex' => 0,
                'AssociatePublicIpAddress' => true,
                'SecurityGroupId' => ['sg-1', 'sg-2'],
            ]],
            'TagSpecification' => [[
                'ResourceType' => 'instance',
                'Tag' => [['Key' => 'Name', 'Value' => 'web-tokyo-aaaaaa']],
            ]],
        ]));
    }

    public function testRunInstance(): void
    {
        $this->transport->queue(new Response(200, [], '<?xml version="1.0"?><RunInstancesResponse xmlns="' . self::NS . '">'
            . '<instancesSet><item>' . $this->instanceXml('i-0abc', 'pending', null, null) . '</item></instancesSet>'
            . '</RunInstancesResponse>'));

        $instance = $this->client->runInstance('ap-northeast-1', [
            'ImageId' => 'ami-1',
            'InstanceType' => 'c7i.large',
            'ClientToken' => 'web-tokyo-aaaaaa',
        ]);

        self::assertSame('i-0abc', $instance['id']);
        self::assertSame('pending', $instance['state']);
        self::assertNull($instance['ipv4']);
        self::assertSame(['Name' => 'web-tokyo-aaaaaa', 'dnslb:service' => 'web'], $instance['tags']);

        $request = $this->transport->lastRequest();
        self::assertSame('https://ec2.ap-northeast-1.amazonaws.com/', $request->url);
        parse_str($request->body, $fields);
        self::assertSame('RunInstances', $fields['Action']);
        self::assertSame('2016-11-15', $fields['Version']);
        self::assertSame('1', $fields['MinCount']);
        self::assertSame('1', $fields['MaxCount']);
        self::assertSame('web-tokyo-aaaaaa', $fields['ClientToken']);
        self::assertStringContainsString('/ap-northeast-1/ec2/aws4_request', (string) $request->header('Authorization'));
    }

    public function testDescribeInstancesPaginatesAndExtractsAddresses(): void
    {
        $this->transport->queue(
            new Response(200, [], '<DescribeInstancesResponse xmlns="' . self::NS . '"><reservationSet><item><instancesSet><item>'
                . $this->instanceXml('i-1', 'running', '203.0.113.10', '2001:db8::10')
                . '</item></instancesSet></item></reservationSet><nextToken>tok-2</nextToken></DescribeInstancesResponse>'),
            new Response(200, [], '<DescribeInstancesResponse xmlns="' . self::NS . '"><reservationSet><item><instancesSet><item>'
                . $this->instanceXml('i-2', 'terminated', null, null)
                . '</item></instancesSet></item></reservationSet></DescribeInstancesResponse>')
        );

        $instances = $this->client->describeInstances('eu-central-1', [['Name' => 'tag:dnslb:service', 'Value' => ['web']]]);

        self::assertCount(2, $instances);
        self::assertSame('203.0.113.10', $instances[0]['ipv4']);
        self::assertSame('2001:db8::10', $instances[0]['ipv6']);
        self::assertSame('ap-northeast-1a', $instances[0]['az']);
        self::assertSame(1700000000, $instances[0]['launch_time']);
        self::assertSame('terminated', $instances[1]['state']);

        parse_str($this->transport->requests[0]->body, $first);
        self::assertSame('tag:dnslb:service', $first['Filter_1_Name']);
        self::assertSame('1000', $first['MaxResults']);
        parse_str($this->transport->requests[1]->body, $second);
        self::assertSame('tok-2', $second['NextToken']);
    }

    public function testErrorParsing(): void
    {
        $this->transport->queue(new Response(400, [], '<?xml version="1.0"?><Response><Errors><Error>'
            . '<Code>InsufficientInstanceCapacity</Code><Message>We currently do not have sufficient capacity</Message>'
            . '</Error></Errors><RequestID>req-9</RequestID></Response>'));

        try {
            $this->client->terminateInstances('us-east-1', ['i-1']);
            self::fail('Expected AwsException');
        } catch (AwsException $e) {
            self::assertSame('InsufficientInstanceCapacity', $e->getErrorCode());
            self::assertSame('req-9', $e->getRequestId());
            self::assertFalse($e->isRetryable());
        }
    }

    public function testRequestLimitExceededIsRetried(): void
    {
        $this->transport->queue(
            new Response(503, [], '<Response><Errors><Error><Code>RequestLimitExceeded</Code><Message>slow down</Message></Error></Errors></Response>'),
            new Response(200, [], '<TerminateInstancesResponse xmlns="' . self::NS . '"/>')
        );

        $this->client->terminateInstances('us-east-1', ['i-1']);

        self::assertCount(2, $this->transport->requests);
    }

    private function instanceXml(string $id, string $state, ?string $ipv4, ?string $ipv6): string
    {
        $xml = "<instanceId>{$id}</instanceId><instanceType>c7i.large</instanceType>"
            . "<instanceState><code>16</code><name>{$state}</name></instanceState>"
            . '<placement><availabilityZone>ap-northeast-1a</availabilityZone></placement>'
            . '<launchTime>2023-11-14T22:13:20.000Z</launchTime><privateIpAddress>10.0.0.5</privateIpAddress>';
        if ($ipv4 !== null) {
            $xml .= "<ipAddress>{$ipv4}</ipAddress>";
        }
        $xml .= '<networkInterfaceSet><item><networkInterfaceId>eni-1</networkInterfaceId><ipv6AddressesSet>';
        if ($ipv6 !== null) {
            $xml .= "<item><ipv6Address>{$ipv6}</ipv6Address></item>";
        }
        $xml .= '</ipv6AddressesSet></item></networkInterfaceSet>';
        return $xml . '<tagSet><item><key>Name</key><value>web-tokyo-aaaaaa</value></item>'
            . '<item><key>dnslb:service</key><value>web</value></item></tagSet>';
    }
}
