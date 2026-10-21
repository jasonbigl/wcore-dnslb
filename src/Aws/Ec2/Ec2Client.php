<?php

namespace Wcore\Dnslb\Aws\Ec2;

use Wcore\Dnslb\Aws\AwsClient;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * EC2 Query 接口（API 版本 2016-11-15）。
 *
 * 实例统一转换成数组：
 * [id, state, type, ipv4, ipv6, private_ipv4, az, launch_time, tags]
 */
final class Ec2Client extends AwsClient
{
    private const VERSION = '2016-11-15';
    private const SERVICE = 'ec2';

    /**
     * 调用任意 EC2 Action。参数使用嵌套数组，自动展开成 Query 格式，例如：
     * ['Filter' => [['Name' => 'tag:app', 'Value' => ['web']]]] → Filter.1.Name=tag:app&Filter.1.Value.1=web
     */
    public function call(string $region, string $action, array $params = []): \SimpleXMLElement
    {
        $fields = ['Action' => $action, 'Version' => self::VERSION] + self::flatten($params);
        $request = new Request(
            'POST',
            "https://ec2.{$region}.amazonaws.com/",
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8'],
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
        return self::requireXml($this->send($request, self::SERVICE, $region));
    }

    /**
     * 启动一台实例。$params 为 RunInstances 的参数（MinCount / MaxCount 固定为 1）。
     * 建议传入 ClientToken 保证重试幂等，避免网络超时后重复创建。
     *
     * @return array 实例信息
     */
    public function runInstance(string $region, array $params): array
    {
        $params['MinCount'] = 1;
        $params['MaxCount'] = 1;
        $xml = $this->call($region, 'RunInstances', $params);
        return self::normalizeInstance($xml->instancesSet->item);
    }

    /**
     * @param array $filters    例如 [['Name' => 'tag:dnslb:service', 'Value' => ['web']]]
     * @param string[] $instanceIds
     * @return array[] 实例信息列表
     */
    public function describeInstances(string $region, array $filters = [], array $instanceIds = []): array
    {
        $params = [];
        if ($filters !== []) {
            $params['Filter'] = $filters;
        }
        if ($instanceIds !== []) {
            $params['InstanceId'] = array_values($instanceIds);
        } else {
            $params['MaxResults'] = 1000;
        }

        $instances = [];
        do {
            $xml = $this->call($region, 'DescribeInstances', $params);
            foreach ($xml->reservationSet->item ?? [] as $reservation) {
                foreach ($reservation->instancesSet->item ?? [] as $item) {
                    $instances[] = self::normalizeInstance($item);
                }
            }
            $next = (string) ($xml->nextToken ?? '');
            $params['NextToken'] = $next;
        } while ($next !== '');

        return $instances;
    }

    /**
     * @param string[] $instanceIds
     */
    public function terminateInstances(string $region, array $instanceIds): void
    {
        if ($instanceIds === []) {
            return;
        }
        $this->call($region, 'TerminateInstances', ['InstanceId' => array_values($instanceIds)]);
    }

    /**
     * @return array{id:string,name:string,state:string,root_device:string}|null 镜像不存在时返回 null
     */
    public function describeImage(string $region, string $imageId): ?array
    {
        $xml = $this->call($region, 'DescribeImages', ['ImageId' => [$imageId]]);
        $item = $xml->imagesSet->item ?? null;
        if ($item === null || !isset($item->imageId)) {
            return null;
        }
        return [
            'id' => (string) $item->imageId,
            'name' => (string) $item->name,
            'state' => (string) $item->imageState,
            'root_device' => (string) $item->rootDeviceName,
        ];
    }

    /**
     * @return array{id:string,vpc:string,az:string,ipv6:bool}|null 子网不存在时返回 null
     */
    public function describeSubnet(string $region, string $subnetId): ?array
    {
        $xml = $this->call($region, 'DescribeSubnets', ['SubnetId' => [$subnetId]]);
        $item = $xml->subnetSet->item ?? null;
        if ($item === null || !isset($item->subnetId)) {
            return null;
        }
        $ipv6 = false;
        foreach ($item->ipv6CidrBlockAssociationSet->item ?? [] as $association) {
            if ((string) $association->ipv6CidrBlockState->state === 'associated') {
                $ipv6 = true;
            }
        }
        return [
            'id' => (string) $item->subnetId,
            'vpc' => (string) $item->vpcId,
            'az' => (string) $item->availabilityZone,
            'ipv6' => $ipv6,
        ];
    }

    protected function parseError(Response $response): AwsException
    {
        return self::parseXmlError($response);
    }

    /**
     * 把嵌套数组展开成 EC2 Query 参数。列表从 1 开始编号，布尔值转成 true / false。
     *
     * @return array<string,string>
     */
    public static function flatten(array $params, string $prefix = ''): array
    {
        $result = [];
        $isList = $params === [] || array_keys($params) === range(0, count($params) - 1);
        foreach ($params as $key => $value) {
            $name = $isList ? $prefix . ($key + 1) : $prefix . $key;
            if (is_array($value)) {
                $result += self::flatten($value, $name . '.');
            } elseif (is_bool($value)) {
                $result[$name] = $value ? 'true' : 'false';
            } elseif ($value !== null) {
                $result[$name] = (string) $value;
            }
        }
        return $result;
    }

    public static function normalizeInstance(\SimpleXMLElement $item): array
    {
        $tags = [];
        foreach ($item->tagSet->item ?? [] as $tag) {
            $tags[(string) $tag->key] = (string) $tag->value;
        }

        $ipv6 = null;
        foreach ($item->networkInterfaceSet->item ?? [] as $interface) {
            foreach ($interface->ipv6AddressesSet->item ?? [] as $address) {
                $ipv6 = (string) $address->ipv6Address;
                break 2;
            }
        }
        if ($ipv6 === null && isset($item->ipv6Address) && (string) $item->ipv6Address !== '') {
            $ipv6 = (string) $item->ipv6Address;
        }

        $launchTime = strtotime((string) $item->launchTime);

        return [
            'id' => (string) $item->instanceId,
            'state' => (string) $item->instanceState->name,
            'type' => (string) $item->instanceType,
            'ipv4' => isset($item->ipAddress) && (string) $item->ipAddress !== '' ? (string) $item->ipAddress : null,
            'ipv6' => $ipv6,
            'private_ipv4' => isset($item->privateIpAddress) ? (string) $item->privateIpAddress : null,
            'az' => (string) $item->placement->availabilityZone,
            'launch_time' => $launchTime === false ? null : $launchTime,
            'tags' => $tags,
        ];
    }
}
