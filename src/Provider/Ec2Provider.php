<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Aws\Ec2\Ec2Client;
use Wcore\Dnslb\Config\LaunchSpec;

/**
 * AWS EC2 实例。用节点 ID 作为 ClientToken，重试不会重复创建；
 * 节点 ID 同时写入 Name 和 dnslb:node 标签。
 */
final class Ec2Provider implements ProviderInterface
{
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

    private string $name;
    private Ec2Client $client;
    private bool $ipv6;
    /** @var array<string,string> "region/ami" => 根设备名 */
    private array $rootDevices = [];

    public function __construct(string $name, Ec2Client $client, bool $ipv6)
    {
        $this->name = $name;
        $this->client = $client;
        $this->ipv6 = $ipv6;
    }

    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance
    {
        $o = $spec->options;
        $interface = [
            'DeviceIndex' => 0,
            'SubnetId' => $o['subnet_id'],
            'AssociatePublicIpAddress' => true,
            'DeleteOnTermination' => true,
        ];
        if (!empty($o['security_group_ids'])) {
            $interface['SecurityGroupId'] = $o['security_group_ids'];
        }
        if ($this->ipv6) {
            $interface['Ipv6AddressCount'] = 1;
        }

        $tagList = [];
        // 管理用的标签优先，配置中的自定义标签不能覆盖它们
        foreach (['Name' => $nodeId, self::TAG_NODE => $nodeId] + $tags + ($o['tags'] ?? []) as $key => $value) {
            $tagList[] = ['Key' => $key, 'Value' => $value];
        }

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
            return $this->toInstance($spec->region, $this->client->runInstance($spec->region, $params));
        } catch (AwsException $e) {
            // 重试用尽后仍是限流或 5xx：之前某次请求可能已经建出实例（ClientToken 只在本区域去重）
            if ($e->isRetryable()) {
                throw new OutcomeUnknownException("EC2 {$spec->region}: create outcome unknown: {$e->getMessage()}", 0, $e);
            }
            throw self::wrap($spec->region, $e);
        }
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

    private function toInstance(string $region, array $item): Instance
    {
        return new Instance(
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
    }

    private static function wrap(string $region, AwsException $e): ProviderException
    {
        return new ProviderException("EC2 {$region}: {$e->getMessage()}", $e->getErrorCode(), $e);
    }
}
