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
    private FakeClock $clock;
    private Ec2Provider $provider;
    private LaunchSpec $spec;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->clock = new FakeClock();
        $this->provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock), true, $this->clock);
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
        $provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock), false, $this->clock);
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
        $provider = new Ec2Provider('aws', new Ec2Client($this->transport, new Credentials('AKID', 'SECRET'), $this->clock), false, $this->clock);
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

    public function testConsoleOutput(): void
    {
        $this->transport->queue(new Response(200, [], '<GetConsoleOutputResponse xmlns="' . self::NS . '"><instanceId>i-1</instanceId>'
            . '<output>' . base64_encode("cloud-init ok\n") . '</output></GetConsoleOutputResponse>'));

        self::assertSame("cloud-init ok\n", $this->provider->consoleOutput('ap-northeast-1', 'i-1'));
        parse_str($this->transport->lastRequest()->body, $params);
        self::assertSame(['Action' => 'GetConsoleOutput', 'Version' => '2016-11-15', 'InstanceId' => 'i-1', 'Latest' => 'true'], array_intersect_key($params, array_flip(['Action', 'Version', 'InstanceId', 'Latest'])));
    }

    public function testConsoleOutputFallsBackWithoutLatestAndReportsEmpty(): void
    {
        $this->transport->queue(
            new Response(400, [], '<Response><Errors><Error><Code>UnsupportedOperation</Code><Message>Latest is only supported on Nitro</Message></Error></Errors></Response>'),
            new Response(200, [], '<GetConsoleOutputResponse xmlns="' . self::NS . '"><instanceId>i-1</instanceId></GetConsoleOutputResponse>')
        );

        self::assertNull($this->provider->consoleOutput('ap-northeast-1', 'i-1'));
        parse_str($this->transport->lastRequest()->body, $params);
        self::assertArrayNotHasKey('Latest', $params);
    }

    public function testConsoleOutputAccessDeniedIsNotRetried(): void
    {
        $this->transport->queue(new Response(403, [], '<Response><Errors><Error><Code>UnauthorizedOperation</Code><Message>not authorized</Message></Error></Errors></Response>'));

        try {
            $this->provider->consoleOutput('ap-northeast-1', 'i-1');
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('UnauthorizedOperation', $e->getErrorCode());
        }
        self::assertCount(1, $this->transport->requests);
    }

    public function testCreateWithElasticIpAllocatesFirstAndAssociatesOnceRunning(): void
    {
        $this->transport->queue(
            self::addresses([]),
            self::allocated('eipalloc-1', '203.0.113.10'),
            self::launched('i-0new', ['dnslb:node' => 'web-tokyo-abc123', 'dnslb:eip' => 'true']),
            self::instances('i-0new', 'pending'),
            self::instances('i-0new', 'running'),
            self::ok('AssociateAddress')
        );

        $instance = $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', ['dnslb:service' => 'web', 'dnslb:pool' => 'tokyo']);

        self::assertSame(Instance::RUNNING, $instance->status);
        self::assertSame('203.0.113.10', $instance->ipv4);
        self::assertTrue($instance->elasticIp);
        self::assertSame(
            ['DescribeAddresses', 'AllocateAddress', 'RunInstances', 'DescribeInstances', 'DescribeInstances', 'AssociateAddress'],
            $this->actions()
        );
        self::assertSame([2000000, 2000000], $this->clock->sleeps);

        $lookup = self::fields($this->transport->requests[0]->body);
        self::assertSame('tag:dnslb:node', $lookup['Filter.1.Name']);
        self::assertSame('web-tokyo-abc123', $lookup['Filter.1.Value.1']);

        $allocate = self::fields($this->transport->requests[1]->body);
        self::assertSame('vpc', $allocate['Domain']);
        self::assertSame('elastic-ip', $allocate['TagSpecification.1.ResourceType']);
        self::assertSame(
            ['Name' => 'web-tokyo-abc123', 'dnslb:node' => 'web-tokyo-abc123', 'dnslb:service' => 'web', 'dnslb:pool' => 'tokyo', 'team' => 'web'],
            self::tags($allocate, 1)
        );

        $run = self::fields($this->transport->requests[2]->body);
        self::assertSame('false', $run['NetworkInterface.1.AssociatePublicIpAddress'], 'the elastic IP is the only public IPv4');
        self::assertSame('true', self::tags($run, 1)['dnslb:eip']);

        $associate = self::fields($this->transport->requests[5]->body);
        self::assertSame('eipalloc-1', $associate['AllocationId']);
        self::assertSame('i-0new', $associate['InstanceId']);
    }

    public function testElasticIpQuotaErrorFailsTheLaunchBeforeAnyInstanceIsCreated(): void
    {
        $this->transport->queue(self::addresses([]), self::error('AddressLimitExceeded', 'The maximum number of addresses has been reached.'));

        try {
            $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('AddressLimitExceeded', $e->getErrorCode());
        }
        self::assertSame(['DescribeAddresses', 'AllocateAddress'], $this->actions());
    }

    public function testRejectedLaunchReleasesTheElasticIp(): void
    {
        $this->transport->queue(
            self::addresses([]),
            self::allocated('eipalloc-1', '203.0.113.10'),
            self::error('InsufficientInstanceCapacity', 'no capacity', 500),
            self::ok('ReleaseAddress')
        );

        try {
            $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('InsufficientInstanceCapacity', $e->getErrorCode());
        }
        self::assertSame(['DescribeAddresses', 'AllocateAddress', 'RunInstances', 'ReleaseAddress'], $this->actions());
        self::assertSame('eipalloc-1', self::fields($this->transport->lastRequest()->body)['AllocationId']);
    }

    public function testUnknownLaunchOutcomeKeepsTheElasticIpAndTheRetryReusesIt(): void
    {
        $throttled = self::error('RequestLimitExceeded', 'Request limit exceeded.', 503);
        $this->transport->queue(
            self::addresses([]),
            self::allocated('eipalloc-1', '203.0.113.10'),
            $throttled,
            $throttled,
            $throttled,
            $throttled,
            $throttled
        );
        try {
            $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);
            self::fail('Expected OutcomeUnknownException');
        } catch (OutcomeUnknownException $e) {
            self::assertNotContains('ReleaseAddress', $this->actions());
        }

        $this->transport->requests = [];
        $this->transport->queue(
            self::addresses([['eipalloc-1', '203.0.113.10', null, 'web-tokyo-abc123']]),
            self::launched('i-0new', ['dnslb:node' => 'web-tokyo-abc123', 'dnslb:eip' => 'true']),
            self::instances('i-0new', 'running'),
            self::ok('AssociateAddress')
        );
        $instance = $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);

        self::assertSame(['DescribeAddresses', 'RunInstances', 'DescribeInstances', 'AssociateAddress'], $this->actions(), 'no second allocation');
        self::assertSame('203.0.113.10', $instance->ipv4);
    }

    public function testInstanceThatStaysPendingIsReturnedWithoutAnAddress(): void
    {
        $this->transport->queue(
            self::addresses([]),
            self::allocated('eipalloc-1', '203.0.113.10'),
            self::launched('i-0new', ['dnslb:node' => 'web-tokyo-abc123', 'dnslb:eip' => 'true']),
            // 刚建出的实例可能暂时查不到
            self::error('InvalidInstanceID.NotFound', 'not yet')
        );
        $this->transport->handle(static function (): Response {
            return self::instances('i-0new', 'pending');
        });
        $started = $this->clock->now();

        $instance = $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);

        self::assertSame(Instance::PENDING, $instance->status);
        self::assertNull($instance->ipv4);
        self::assertTrue($instance->elasticIp, 'the tick associates the address later');
        self::assertNotContains('AssociateAddress', $this->actions());
        self::assertSame(40, $this->clock->now() - $started);
    }

    public function testAssociationErrorsDoNotFailTheLaunch(): void
    {
        $this->transport->queue(
            self::addresses([]),
            self::allocated('eipalloc-1', '203.0.113.10'),
            self::launched('i-0new', ['dnslb:node' => 'web-tokyo-abc123', 'dnslb:eip' => 'true']),
            self::instances('i-0new', 'running'),
            self::error('UnauthorizedOperation', 'not authorized', 403)
        );

        $instance = $this->provider->create($this->elasticSpec(), 'web-tokyo-abc123', '', []);

        self::assertSame('i-0new', $instance->id);
        self::assertNull($instance->ipv4);
        self::assertCount(5, $this->transport->requests, 'gives up waiting on an error that will not go away');
    }

    public function testAttachAddress(): void
    {
        // 还没关联：关联并返回地址
        $this->transport->queue(self::addresses([['eipalloc-1', '203.0.113.10', null, 'web-tokyo-a']]), self::ok('AssociateAddress'));
        self::assertSame('203.0.113.10', $this->provider->attachAddress('ap-northeast-1', 'web-tokyo-a', 'i-1'));
        self::assertSame(['DescribeAddresses', 'AssociateAddress'], $this->actions());

        // 已经关联到这台实例：不重复关联
        $this->transport->requests = [];
        $this->transport->queue(self::addresses([['eipalloc-1', '203.0.113.10', 'i-1', 'web-tokyo-a']]));
        self::assertSame('203.0.113.10', $this->provider->attachAddress('ap-northeast-1', 'web-tokyo-a', 'i-1'));
        self::assertSame(['DescribeAddresses'], $this->actions());

        // 节点名下没有弹性 IP
        $this->transport->queue(self::addresses([]));
        self::assertNull($this->provider->attachAddress('ap-northeast-1', 'web-tokyo-a', 'i-1'));

        // 实例还不能关联
        $this->transport->queue(self::addresses([['eipalloc-1', '203.0.113.10', null, 'web-tokyo-a']]), self::error('IncorrectInstanceState', 'pending'));
        try {
            $this->provider->attachAddress('ap-northeast-1', 'web-tokyo-a', 'i-1');
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('IncorrectInstanceState', $e->getErrorCode());
        }
    }

    public function testReleaseAddressesDetachesAndReleasesEverythingTaggedWithTheNode(): void
    {
        $this->transport->queue(
            self::addresses([['eipalloc-1', '203.0.113.10', 'i-1', 'web-tokyo-a'], ['eipalloc-2', '203.0.113.11', null, 'web-tokyo-a']]),
            // 实例删除后关联已经自动解除
            self::error('InvalidAssociationID.NotFound', 'gone'),
            self::ok('ReleaseAddress'),
            self::error('InvalidAllocationID.NotFound', 'gone')
        );

        $this->provider->releaseAddresses('ap-northeast-1', 'web-tokyo-a');

        self::assertSame(['DescribeAddresses', 'DisassociateAddress', 'ReleaseAddress', 'ReleaseAddress'], $this->actions());
        self::assertSame('eipassoc-eipalloc-1', self::fields($this->transport->requests[1]->body)['AssociationId']);
        self::assertSame('eipalloc-2', self::fields($this->transport->requests[3]->body)['AllocationId']);

        // 没有弹性 IP 时什么都不做
        $this->transport->requests = [];
        $this->transport->queue(self::addresses([]));
        $this->provider->releaseAddresses('ap-northeast-1', 'web-tokyo-a');
        self::assertSame(['DescribeAddresses'], $this->actions());
    }

    public function testReleaseAddressesReportsAnAddressThatIsStillInUse(): void
    {
        $this->transport->queue(
            self::addresses([['eipalloc-1', '203.0.113.10', null, 'web-tokyo-a']]),
            self::error('InvalidIPAddress.InUse', 'Address is in use')
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('InvalidIPAddress.InUse');
        $this->provider->releaseAddresses('ap-northeast-1', 'web-tokyo-a');
    }

    public function testReleaseAddressLeavesAnAssociatedAddressAloneUnlessDetaching(): void
    {
        $associated = self::addresses([['eipalloc-1', '203.0.113.10', 'i-1', 'web-tokyo-a']]);
        $this->transport->queue($associated);
        try {
            $this->provider->releaseAddress('ap-northeast-1', 'eipalloc-1', false);
            self::fail('Expected ProviderException');
        } catch (ProviderException $e) {
            self::assertSame('AddressInUse', $e->getErrorCode());
            self::assertStringContainsString('i-1', $e->getMessage());
        }
        self::assertSame(['DescribeAddresses'], $this->actions());
        self::assertSame('eipalloc-1', self::fields($this->transport->requests[0]->body)['AllocationId.1']);

        $this->transport->requests = [];
        $this->transport->queue($associated, self::ok('DisassociateAddress'), self::ok('ReleaseAddress'));
        $this->provider->releaseAddress('ap-northeast-1', 'eipalloc-1', true);
        self::assertSame(['DescribeAddresses', 'DisassociateAddress', 'ReleaseAddress'], $this->actions());

        // 已经不存在
        $this->transport->queue(self::error('InvalidAllocationID.NotFound', 'gone'));
        $this->provider->releaseAddress('ap-northeast-1', 'eipalloc-1', false);
    }

    public function testListAddresses(): void
    {
        $this->transport->queue(
            self::addresses([['eipalloc-1', '203.0.113.10', 'i-1', 'web-tokyo-a'], ['eipalloc-2', '203.0.113.11', null, null]]),
            self::addresses([])
        );

        $addresses = $this->provider->listAddresses('web', ['ap-northeast-1', 'eu-central-1', 'ap-northeast-1']);

        self::assertCount(2, $this->transport->requests);
        $fields = self::fields($this->transport->requests[0]->body);
        self::assertSame('tag:dnslb:service', $fields['Filter.1.Name']);
        self::assertSame('web', $fields['Filter.1.Value.1']);
        self::assertCount(2, $addresses);
        self::assertSame(['aws', 'eipalloc-1', 'ap-northeast-1', '203.0.113.10', 'i-1', 'web-tokyo-a'], [
            $addresses[0]->provider, $addresses[0]->id, $addresses[0]->region, $addresses[0]->ip, $addresses[0]->instanceId, $addresses[0]->nodeId,
        ]);
        self::assertNull($addresses[1]->instanceId);
        self::assertNull($addresses[1]->nodeId);
    }

    public function testInstancesLaunchedWithAnElasticIpAreMarked(): void
    {
        $this->transport->queue(new Response(200, [], '<DescribeInstancesResponse xmlns="' . self::NS . '"><reservationSet><item><instancesSet>'
            . self::instanceItem('i-1', 'running', ['dnslb:node' => 'web-tokyo-a', 'dnslb:eip' => 'true'])
            . self::instanceItem('i-2', 'running', ['dnslb:node' => 'web-tokyo-b'])
            . '</instancesSet></item></reservationSet></DescribeInstancesResponse>'));

        $instances = $this->provider->listManaged('web', ['ap-northeast-1']);

        self::assertTrue($instances[0]->elasticIp);
        self::assertFalse($instances[1]->elasticIp);
    }

    private function elasticSpec(): LaunchSpec
    {
        $spec = clone $this->spec;
        $spec->options['volume_size'] = null;
        $spec->options['elastic_ip'] = true;
        return $spec;
    }

    /**
     * @return string[] 已发出请求的 Action
     */
    private function actions(): array
    {
        return array_map(static function ($request): string {
            return self::fields($request->body)['Action'];
        }, $this->transport->requests);
    }

    /**
     * @return array<string,string> 请求中第 $spec 个 TagSpecification 的标签
     */
    private static function tags(array $fields, int $spec): array
    {
        $tags = [];
        for ($i = 1; isset($fields["TagSpecification.{$spec}.Tag.{$i}.Key"]); $i++) {
            $tags[$fields["TagSpecification.{$spec}.Tag.{$i}.Key"]] = $fields["TagSpecification.{$spec}.Tag.{$i}.Value"];
        }
        return $tags;
    }

    /**
     * @param array<array{0:string,1:string,2:?string,3:?string}> $items [allocation ID, IP, 关联的实例 ID, 节点 ID]
     */
    private static function addresses(array $items): Response
    {
        $xml = '';
        foreach ($items as [$id, $ip, $instanceId, $nodeId]) {
            $xml .= "<item><publicIp>{$ip}</publicIp><allocationId>{$id}</allocationId><domain>vpc</domain>";
            if ($instanceId !== null) {
                $xml .= "<instanceId>{$instanceId}</instanceId><associationId>eipassoc-{$id}</associationId>";
            }
            $xml .= '<tagSet>' . ($nodeId === null ? '' : "<item><key>dnslb:node</key><value>{$nodeId}</value></item>") . '</tagSet></item>';
        }
        return new Response(200, [], '<DescribeAddressesResponse xmlns="' . self::NS . '"><addressesSet>' . $xml . '</addressesSet></DescribeAddressesResponse>');
    }

    private static function allocated(string $id, string $ip): Response
    {
        return new Response(200, [], '<AllocateAddressResponse xmlns="' . self::NS . '"><publicIp>' . $ip . '</publicIp><domain>vpc</domain>'
            . '<allocationId>' . $id . '</allocationId></AllocateAddressResponse>');
    }

    /**
     * @param array<string,string> $tags
     */
    private static function launched(string $id, array $tags): Response
    {
        return new Response(200, [], '<RunInstancesResponse xmlns="' . self::NS . '"><instancesSet>' . self::instanceItem($id, 'pending', $tags)
            . '</instancesSet></RunInstancesResponse>');
    }

    private static function instances(string $id, string $state): Response
    {
        return new Response(200, [], '<DescribeInstancesResponse xmlns="' . self::NS . '"><reservationSet><item><instancesSet>'
            . self::instanceItem($id, $state, []) . '</instancesSet></item></reservationSet></DescribeInstancesResponse>');
    }

    /**
     * @param array<string,string> $tags
     */
    private static function instanceItem(string $id, string $state, array $tags): string
    {
        $tagSet = '';
        foreach ($tags as $key => $value) {
            $tagSet .= "<item><key>{$key}</key><value>{$value}</value></item>";
        }
        return "<item><instanceId>{$id}</instanceId><instanceState><name>{$state}</name></instanceState>"
            . "<placement><availabilityZone>ap-northeast-1a</availabilityZone></placement><tagSet>{$tagSet}</tagSet></item>";
    }

    private static function ok(string $action): Response
    {
        return new Response(200, [], "<{$action}Response xmlns=\"" . self::NS . "\"><return>true</return></{$action}Response>");
    }

    private static function error(string $code, string $message, int $status = 400): Response
    {
        return new Response($status, [], "<Response><Errors><Error><Code>{$code}</Code><Message>{$message}</Message></Error></Errors></Response>");
    }
}
