<?php

namespace Wcore\Dnslb\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Credentials;
use Wcore\Dnslb\Aws\Ec2\Ec2Client;
use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Provider\Ec2Provider;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeTransport;
use Wcore\Dnslb\Tests\Support\TestApp;

final class Ec2ProviderTest extends TestCase
{
    private const NS = 'http://ec2.amazonaws.com/doc/2016-11-15/';

    private FakeTransport $transport;
    private Ec2Provider $provider;
    private LaunchSpec $spec;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), new FakeClock()), true);
        $config = TestApp::config(['pools' => ['tokyo' => ['launch' => [[
            'provider' => 'aws',
            'region' => 'ap-northeast-1',
            'instance_type' => 'c7i.large',
            'ami' => 'ami-0abc',
            'subnet_id' => 'subnet-0abc',
            'security_group_ids' => ['sg-1', 'sg-2'],
            'key_name' => 'ops',
            'volume_size' => 20,
            'iam_instance_profile' => 'node-role',
            'tags' => ['team' => 'web', 'Name' => 'should-not-win'],
        ]]]]]);
        $this->spec = $config->pools['tokyo']->launch[0];
    }

    public function testCreate(): void
    {
        $this->transport->queue(
            new Response(200, [], '<DescribeImagesResponse xmlns="' . self::NS . '"><imagesSet><item><imageId>ami-0abc</imageId>'
                . '<name>ubuntu</name><imageState>available</imageState><rootDeviceName>/dev/sda1</rootDeviceName></item></imagesSet></DescribeImagesResponse>'),
            new Response(200, [], '<RunInstancesResponse xmlns="' . self::NS . '"><instancesSet><item><instanceId>i-0new</instanceId>'
                . '<instanceState><name>pending</name></instanceState><placement><availabilityZone>ap-northeast-1a</availabilityZone></placement>'
                . '<tagSet><item><key>dnslb:node</key><value>web-tokyo-abc123</value></item></tagSet></item></instancesSet></RunInstancesResponse>')
        );

        $instance = $this->provider->create($this->spec, 'web-tokyo-abc123', "#cloud-config\n", ['dnslb:service' => 'web', 'dnslb:pool' => 'tokyo']);

        self::assertSame('i-0new', $instance->id);
        self::assertSame(Instance::PENDING, $instance->status);
        self::assertSame('web-tokyo-abc123', $instance->nodeId);

        $fields = self::fields($this->transport->lastRequest()->body);
        self::assertSame('RunInstances', $fields['Action']);
        self::assertSame('ami-0abc', $fields['ImageId']);
        self::assertSame('c7i.large', $fields['InstanceType']);
        self::assertSame('web-tokyo-abc123', $fields['ClientToken']);
        self::assertSame("#cloud-config\n", base64_decode($fields['UserData']));
        self::assertSame('subnet-0abc', $fields['NetworkInterface.1.SubnetId']);
        self::assertSame('0', $fields['NetworkInterface.1.DeviceIndex']);
        self::assertSame('true', $fields['NetworkInterface.1.AssociatePublicIpAddress']);
        self::assertSame('1', $fields['NetworkInterface.1.Ipv6AddressCount']);
        self::assertSame('sg-2', $fields['NetworkInterface.1.SecurityGroupId.2']);
        self::assertSame('ops', $fields['KeyName']);
        self::assertSame('node-role', $fields['IamInstanceProfile.Name']);
        self::assertSame('required', $fields['MetadataOptions.HttpTokens']);
        self::assertSame('/dev/sda1', $fields['BlockDeviceMapping.1.DeviceName']);
        self::assertSame('20', $fields['BlockDeviceMapping.1.Ebs.VolumeSize']);

        $tags = [];
        for ($i = 1; isset($fields["TagSpecification.1.Tag.{$i}.Key"]); $i++) {
            $tags[$fields["TagSpecification.1.Tag.{$i}.Key"]] = $fields["TagSpecification.1.Tag.{$i}.Value"];
        }
        self::assertSame([
            'Name' => 'web-tokyo-abc123',
            'dnslb:node' => 'web-tokyo-abc123',
            'dnslb:service' => 'web',
            'dnslb:pool' => 'tokyo',
            'team' => 'web',
        ], $tags);
        self::assertSame('volume', $fields['TagSpecification.2.ResourceType']);
    }

    public function testCreateErrorsAreProviderExceptions(): void
    {
        $provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), new FakeClock()), false);
        $spec = clone $this->spec;
        $spec->options['volume_size'] = null;
        $this->transport->queue(new Response(500, [], '<Response><Errors><Error><Code>InsufficientInstanceCapacity</Code>'
            . '<Message>We currently do not have sufficient c7i.large capacity</Message></Error></Errors></Response>'));

        try {
            $provider->create($spec, 'web-tokyo-abc123', '', []);
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('InsufficientInstanceCapacity', $e->getErrorCode());
            self::assertStringContainsString('EC2 ap-northeast-1', $e->getMessage());
        }
        self::assertArrayNotHasKey('NetworkInterface.1.Ipv6AddressCount', self::fields($this->transport->lastRequest()->body));
    }

    public function testThrottlingAfterATimeoutLeavesTheOutcomeUnknown(): void
    {
        $provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), new FakeClock()), false);
        $spec = clone $this->spec;
        $spec->options['volume_size'] = null;
        // 第一次请求超时（实例可能已建出），之后的重试都被限流
        $throttled = new Response(503, [], '<Response><Errors><Error><Code>RequestLimitExceeded</Code><Message>Request limit exceeded.</Message></Error></Errors></Response>');
        $this->transport->queue(new HttpException('Operation timed out'), $throttled, $throttled, $throttled, $throttled);

        $this->expectException(OutcomeUnknownException::class);
        $this->expectExceptionMessage('EC2 ap-northeast-1: create outcome unknown');
        $provider->create($spec, 'web-tokyo-abc123', '', []);
    }

    public function testGetMissingAndTerminateMissing(): void
    {
        $notFound = new Response(400, [], '<Response><Errors><Error><Code>InvalidInstanceID.NotFound</Code><Message>gone</Message></Error></Errors></Response>');
        $this->transport->queue($notFound, $notFound);

        self::assertNull($this->provider->get('ap-northeast-1', 'i-gone'));
        $this->provider->terminate('ap-northeast-1', 'i-gone');
        self::assertCount(2, $this->transport->requests);
    }

    public function testListManagedQueriesEachRegionWithFilters(): void
    {
        $empty = '<DescribeInstancesResponse xmlns="' . self::NS . '"><reservationSet/></DescribeInstancesResponse>';
        $this->transport->queue(new Response(200, [], $empty), new Response(200, [], $empty));

        $this->provider->listManaged('web', ['ap-northeast-1', 'eu-central-1', 'ap-northeast-1']);

        self::assertCount(2, $this->transport->requests);
        self::assertSame('https://ec2.eu-central-1.amazonaws.com/', $this->transport->requests[1]->url);
        $fields = self::fields($this->transport->requests[0]->body);
        self::assertSame('tag:dnslb:service', $fields['Filter.1.Name']);
        self::assertSame('web', $fields['Filter.1.Value.1']);
        self::assertSame('instance-state-name', $fields['Filter.2.Name']);
        self::assertSame('running', $fields['Filter.2.Value.2']);
    }

    /**
     * 解析表单编码的请求体（parse_str 会把键名中的点号改成下划线，这里保留原样）。
     */
    private static function fields(string $body): array
    {
        $fields = [];
        foreach (explode('&', $body) as $pair) {
            [$key, $value] = array_map('rawurldecode', explode('=', $pair, 2) + [1 => '']);
            $fields[$key] = $value;
        }
        return $fields;
    }
}
