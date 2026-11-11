<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Aws\Ec2\Ec2Client;
use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Support\Clock;

/**
 * AWS EC2 实例。用节点 ID 作为 ClientToken，重试不会重复创建；
 * 节点 ID 同时写入 Name 和 dnslb:node 标签。
 *
 * launch 配置了 elastic_ip 时，每台实例使用一个弹性 IP 作为公网 IPv4：先申请（配额不足时不会白建一台机器），
 * 再建机（不分配自动公网 IP），实例 running 后关联。弹性 IP 带有和实例相同的标签，按 dnslb:node 标签归属到节点，
 * 不需要另外保存它的 ID：重试时复用已有的，节点结束时释放该标签下的全部地址。
 */
final class Ec2Provider implements ProviderInterface, ConsoleOutputProvider, ElasticIpProvider
{
    /** 使用弹性 IP 的实例带有这个标签（rebuildState 据此恢复节点上的标记） */
    public const TAG_ELASTIC_IP = 'dnslb:eip';

    /** 未被删除的实例状态 */
    private const ACTIVE_STATES = ['pending', 'running', 'stopping', 'stopped', 'shutting-down'];
    private const STATUS_MAP = [
        'pending' => Instance::PENDING,
        'running' => Instance::RUNNING,
        'stopping' => Instance::STOPPED,
        'stopped' => Instance::STOPPED,
        'shutting-down' => Instance::GONE,
        'terminated' => Instance::GONE,
    ];
    private const NOT_FOUND_CODES = ['InvalidInstanceID.NotFound', 'InvalidInstanceID.Malformed'];
    private const ADDRESS_NOT_FOUND_CODES = ['InvalidAllocationID.NotFound', 'InvalidAssociationID.NotFound', 'InvalidAddress.NotFound'];
    /**
     * create() 中等待实例 running 的最长时间和查询间隔（秒）：pending 的实例不能关联弹性 IP（IncorrectInstanceState）。
     * 通常 10 ~ 20 秒；超时后由 tick 补上（Reconciler::advanceBooting）。
     */
    private const RUNNING_WAIT = 40;
    private const RUNNING_POLL = 2;

    private string $name;
    private Ec2Client $client;
    private bool $ipv6;
    private Clock $clock;
    /** @var array<string,string> "region/ami" => 根设备名 */
    private array $rootDevices = [];

    public function __construct(string $name, Ec2Client $client, bool $ipv6, Clock $clock)
    {
        $this->name = $name;
        $this->client = $client;
        $this->ipv6 = $ipv6;
        $this->clock = $clock;
    }

    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance
    {
        $o = $spec->options;
        $elastic = !empty($o['elastic_ip']);
        $interface = [
            'DeviceIndex' => 0,
            'SubnetId' => $o['subnet_id'],
            // 使用弹性 IP 时不要自动分配的公网 IP：关联弹性 IP 会把它换掉，开机脚本里正在进行的连接会中断
            'AssociatePublicIpAddress' => !$elastic,
            'DeleteOnTermination' => true,
        ];
        if (!empty($o['security_group_ids'])) {
            $interface['SecurityGroupId'] = $o['security_group_ids'];
        }
        if ($this->ipv6) {
            $interface['Ipv6AddressCount'] = 1;
        }

        // 管理用的标签优先，配置中的自定义标签不能覆盖它们
        $base = ['Name' => $nodeId, self::TAG_NODE => $nodeId] + $tags + ($o['tags'] ?? []);
        unset($base[self::TAG_ELASTIC_IP]);
        $addressTags = self::tagList($base);
        $tagList = self::tagList($elastic ? $base + [self::TAG_ELASTIC_IP => 'true'] : $base);

        $params = [
            'ImageId' => $o['ami'],
            'InstanceType' => $o['instance_type'],
            'ClientToken' => $nodeId,
            'UserData' => base64_encode($userData),
            'NetworkInterface' => [$interface],
            'TagSpecification' => [
                ['ResourceType' => 'instance', 'Tag' => $tagList],
                ['ResourceType' => 'volume', 'Tag' => $tagList],
            ],
            'MetadataOptions' => ['HttpTokens' => 'required', 'HttpEndpoint' => 'enabled'],
        ];
        if (!empty($o['key_name'])) {
            $params['KeyName'] = $o['key_name'];
        }
        if (!empty($o['iam_instance_profile'])) {
            $params['IamInstanceProfile'] = ['Name' => $o['iam_instance_profile']];
        }

        $address = null;
        try {
            if (!empty($o['volume_size']) || !empty($o['volume_type'])) {
                $ebs = ['DeleteOnTermination' => true];
                if (!empty($o['volume_size'])) {
                    $ebs['VolumeSize'] = $o['volume_size'];
                }
                if (!empty($o['volume_type'])) {
                    $ebs['VolumeType'] = $o['volume_type'];
                }
                $params['BlockDeviceMapping'] = [['DeviceName' => $this->rootDevice($spec->region, $o['ami']), 'Ebs' => $ebs]];
            }
            if ($elastic) {
                // 先申请弹性 IP：申请不到（配额用完等）时还没有建机，直接换下一种建机方式
                $address = $this->findOrAllocateAddress($spec->region, $nodeId, $addressTags);
            }
            $item = $this->client->runInstance($spec->region, $params);
        } catch (AwsException $e) {
            // 重试用尽后仍是限流或 5xx：之前某次请求可能已经建出实例（ClientToken 只在本区域去重）。
            // 已申请的弹性 IP 保留，重发时按节点标签复用
            if ($e->isRetryable()) {
                throw new OutcomeUnknownException("EC2 {$spec->region}: create outcome unknown: {$e->getMessage()}", 0, $e);
            }
            if ($address !== null) {
                $this->discardAddress($spec->region, $address);
            }
            throw self::wrap($spec->region, $e);
        }

        $instance = $this->toInstance($spec->region, $item);
        if ($address !== null) {
            $instance->elasticIp = true;
            if ($this->associateWhenRunning($spec->region, $instance->id, $address['id'])) {
                $instance->status = Instance::RUNNING;
                $instance->ipv4 = $address['ip'];
            }
        }
        return $instance;
    }

    public function get(string $region, string $instanceId): ?Instance
    {
        try {
            $items = $this->client->describeInstances($region, [], [$instanceId]);
        } catch (AwsException $e) {
            if (in_array($e->getErrorCode(), self::NOT_FOUND_CODES, true)) {
                return null;
            }
            throw self::wrap($region, $e);
        }
        return $items === [] ? null : $this->toInstance($region, $items[0]);
    }

    public function findByNodeId(string $region, string $nodeId): ?Instance
    {
        $items = $this->describe($region, [['Name' => 'tag:' . self::TAG_NODE, 'Value' => [$nodeId]]]);
        return $items === [] ? null : $items[0];
    }

    public function listManaged(string $service, array $regions): array
    {
        $result = [];
        foreach (array_unique($regions) as $region) {
            foreach ($this->describe($region, [['Name' => 'tag:' . self::TAG_SERVICE, 'Value' => [$service]]]) as $instance) {
                $result[] = $instance;
            }
        }
        return $result;
    }

    /**
     * 最近约 64 KB 的串口输出（Latest，只有 Nitro 实例支持；不支持时退回启动阶段的输出）。
     * 需要 ec2:GetConsoleOutput 权限。
     */
    public function consoleOutput(string $region, string $instanceId): ?string
    {
        try {
            $xml = $this->client->call($region, 'GetConsoleOutput', ['InstanceId' => $instanceId, 'Latest' => 'true']);
        } catch (AwsException $e) {
            if (in_array($e->getErrorCode(), self::NOT_FOUND_CODES, true) || stripos($e->getErrorCode(), 'Unauthorized') !== false
                || $e->getErrorCode() === 'AccessDenied') {
                throw new ProviderException($e->getMessage(), $e->getErrorCode(), $e);
            }
            try {
                $xml = $this->client->call($region, 'GetConsoleOutput', ['InstanceId' => $instanceId]);
            } catch (AwsException $retry) {
                throw new ProviderException($retry->getMessage(), $retry->getErrorCode(), $retry);
            }
        }
        $data = trim((string) $xml->output);
        if ($data === '') {
            return null;
        }
        $decoded = base64_decode($data, true);
        return $decoded === false ? null : $decoded;
    }

    public function terminate(string $region, string $instanceId): void
    {
        try {
            $this->client->terminateInstances($region, [$instanceId]);
        } catch (AwsException $e) {
            if (!in_array($e->getErrorCode(), self::NOT_FOUND_CODES, true)) {
                throw self::wrap($region, $e);
            }
        }
    }

    public function attachAddress(string $region, string $nodeId, string $instanceId): ?string
    {
        try {
            $addresses = $this->addressesOf($region, $nodeId);
            foreach ($addresses as $address) {
                if ($address['instance_id'] === $instanceId) {
                    return $address['ip'];
                }
            }
            foreach ($addresses as $address) {
                if ($address['association_id'] === null) {
                    $this->client->associateAddress($region, $address['id'], $instanceId);
                    return $address['ip'];
                }
            }
        } catch (AwsException $e) {
            throw self::wrap($region, $e);
        }
        if ($addresses === []) {
            return null;
        }
        throw new ProviderException("EC2 {$region}: the elastic IP of {$nodeId} is associated with another instance ({$addresses[0]['instance_id']})", 'AddressInUse');
    }

    public function releaseAddresses(string $region, string $nodeId): void
    {
        try {
            foreach ($this->addressesOf($region, $nodeId) as $address) {
                $this->release($region, $address, true);
            }
        } catch (AwsException $e) {
            throw self::wrap($region, $e);
        }
    }

    public function listAddresses(string $service, array $regions): array
    {
        $result = [];
        foreach (array_unique($regions) as $region) {
            try {
                $addresses = $this->client->describeAddresses($region, [['Name' => 'tag:' . self::TAG_SERVICE, 'Value' => [$service]]]);
            } catch (AwsException $e) {
                throw self::wrap($region, $e);
            }
            foreach ($addresses as $address) {
                $result[] = new Address(
                    $this->name,
                    $address['id'],
                    $region,
                    $address['ip'],
                    $address['instance_id'],
                    $address['tags'][self::TAG_NODE] ?? null,
                    $address['tags']
                );
            }
        }
        return $result;
    }

    public function releaseAddress(string $region, string $addressId, bool $detach): void
    {
        try {
            foreach ($this->client->describeAddresses($region, [], [$addressId]) as $address) {
                $this->release($region, $address, $detach);
            }
        } catch (AwsException $e) {
            if (!in_array($e->getErrorCode(), self::ADDRESS_NOT_FOUND_CODES, true)) {
                throw self::wrap($region, $e);
            }
        }
    }

    /**
     * @return Instance[]
     */
    private function describe(string $region, array $filters): array
    {
        $filters[] = ['Name' => 'instance-state-name', 'Value' => self::ACTIVE_STATES];
        try {
            $items = $this->client->describeInstances($region, $filters);
        } catch (AwsException $e) {
            throw self::wrap($region, $e);
        }
        return array_map(function (array $item) use ($region): Instance {
            return $this->toInstance($region, $item);
        }, $items);
    }

    private function rootDevice(string $region, string $ami): string
    {
        $key = "{$region}/{$ami}";
        if (!isset($this->rootDevices[$key])) {
            $image = $this->client->describeImage($region, $ami);
            if ($image === null || $image['root_device'] === '') {
                throw new ProviderException("EC2 {$region}: AMI {$ami} not found", 'InvalidAMIID.NotFound');
            }
            $this->rootDevices[$key] = $image['root_device'];
        }
        return $this->rootDevices[$key];
    }

    /**
     * 节点名下的弹性 IP（按 dnslb:node 标签）。
     *
     * @return array[] 见 Ec2Client::describeAddresses()
     */
    private function addressesOf(string $region, string $nodeId): array
    {
        return $this->client->describeAddresses($region, [['Name' => 'tag:' . self::TAG_NODE, 'Value' => [$nodeId]]]);
    }

    /**
     * 节点已有弹性 IP 时复用（上次建机请求结果未知后重发），否则申请一个。
     *
     * @param array<array{Key:string,Value:string}> $tags
     * @return array{id:string,ip:string}
     */
    private function findOrAllocateAddress(string $region, string $nodeId, array $tags): array
    {
        $existing = $this->addressesOf($region, $nodeId);
        foreach ($existing as $address) {
            if ($address['association_id'] === null) {
                return $address;
            }
        }
        return $existing[0] ?? $this->client->allocateAddress($region, $tags);
    }

    /**
     * 建机被明确拒绝后释放刚才准备的弹性 IP。失败不影响后续处理：地址带有节点标签，节点结束后由孤儿巡检发现。
     */
    private function discardAddress(string $region, array $address): void
    {
        try {
            $this->client->releaseAddress($region, $address['id']);
        } catch (AwsException | HttpException $e) {
            // 见上
        }
    }

    /**
     * 等实例 running 后关联弹性 IP。
     *
     * @return bool 是否已关联；等待超时或出错时返回 false（实例已经建出，不能当作建机失败），之后由 tick 关联
     */
    private function associateWhenRunning(string $region, string $instanceId, string $allocationId): bool
    {
        $deadline = $this->clock->now() + self::RUNNING_WAIT;
        do {
            $this->clock->sleep(self::RUNNING_POLL * 1000000);
            try {
                $items = $this->client->describeInstances($region, [], [$instanceId]);
                $state = $items === [] ? 'pending' : $items[0]['state'];
                if ($state === 'running') {
                    $this->client->associateAddress($region, $allocationId, $instanceId);
                    return true;
                }
                if ($state !== 'pending') {
                    return false;
                }
            } catch (AwsException $e) {
                // 刚建出的实例可能暂时查不到（最终一致性），刚转为 running 时关联也可能仍被拒绝：继续等
                if (!in_array($e->getErrorCode(), self::NOT_FOUND_CODES, true) && $e->getErrorCode() !== 'IncorrectInstanceState') {
                    return false;
                }
            } catch (HttpException $e) {
                return false;
            }
        } while ($this->clock->now() < $deadline);
        return false;
    }

    /**
     * @param array $address 见 Ec2Client::describeAddresses()
     */
    private function release(string $region, array $address, bool $detach): void
    {
        if ($address['association_id'] !== null) {
            if (!$detach) {
                throw new ProviderException(
                    "EC2 {$region}: elastic IP {$address['id']} ({$address['ip']}) is still associated with " . ($address['instance_id'] ?? 'a network interface'),
                    'AddressInUse'
                );
            }
            try {
                $this->client->disassociateAddress($region, $address['association_id']);
            } catch (AwsException $e) {
                // 实例删除后关联会自动解除
                if (!in_array($e->getErrorCode(), self::ADDRESS_NOT_FOUND_CODES, true)) {
                    throw $e;
                }
            }
        }
        try {
            $this->client->releaseAddress($region, $address['id']);
        } catch (AwsException $e) {
            if (!in_array($e->getErrorCode(), self::ADDRESS_NOT_FOUND_CODES, true)) {
                throw $e;
            }
        }
    }

    private function toInstance(string $region, array $item): Instance
    {
        $instance = new Instance(
            $this->name,
            $item['id'],
            $region,
            self::STATUS_MAP[$item['state']] ?? Instance::PENDING,
            $item['ipv4'],
            $item['ipv6'],
            $item['tags'][self::TAG_NODE] ?? null,
            $item['tags'],
            $item['launch_time']
        );
        $instance->elasticIp = isset($item['tags'][self::TAG_ELASTIC_IP]);
        return $instance;
    }

    /**
     * @param array<string,string> $tags
     * @return array<array{Key:string,Value:string}>
     */
    private static function tagList(array $tags): array
    {
        $list = [];
        foreach ($tags as $key => $value) {
            $list[] = ['Key' => (string) $key, 'Value' => $value];
        }
        return $list;
    }

    private static function wrap(string $region, AwsException $e): ProviderException
    {
        return new ProviderException("EC2 {$region}: {$e->getMessage()}", $e->getErrorCode(), $e);
    }
}
