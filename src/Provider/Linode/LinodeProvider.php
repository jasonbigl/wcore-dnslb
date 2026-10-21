<?php

namespace Wcore\Dnslb\Provider\Linode;

use Wcore\Dnslb\Config\LaunchSpec;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Provider\OutcomeUnknownException;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Provider\ProviderInterface;

/**
 * Linode 实例。实例 label 即节点 ID（账号内唯一，天然防止重复创建），
 * 标签形如 "dnslb:service=web"、"dnslb:pool=tokyo"。
 */
final class LinodeProvider implements ProviderInterface
{
    private const STATUS_MAP = [
        'running' => Instance::RUNNING,
        'offline' => Instance::STOPPED,
        'stopped' => Instance::STOPPED,
        'shutting_down' => Instance::STOPPED,
        'deleting' => Instance::GONE,
    ];

    private string $name;
    private LinodeClient $client;

    public function __construct(string $name, LinodeClient $client)
    {
        $this->name = $name;
        $this->client = $client;
    }

    public function create(LaunchSpec $spec, string $nodeId, string $userData, array $tags): Instance
    {
        $o = $spec->options;
        $body = [
            'label' => $nodeId,
            'region' => $spec->region,
            'type' => $o['type'],
            'image' => $o['image'],
            // root 密码随机生成且不保存，登录使用 authorized_keys
            'root_pass' => self::randomPassword(),
            'tags' => self::tagList($tags, $o['tags'] ?? []),
            'booted' => true,
            'metadata' => ['user_data' => base64_encode($userData)],
        ];
        if (!empty($o['authorized_keys'])) {
            $body['authorized_keys'] = $o['authorized_keys'];
        }
        if (!empty($o['firewall_id'])) {
            $body['firewall_id'] = $o['firewall_id'];
        }
        if (!empty($o['private_ip'])) {
            $body['private_ip'] = true;
        }

        try {
            return $this->toInstance($this->client->request('POST', '/linode/instances', $body));
        } catch (ProviderException $e) {
            // 网络超时后自动重试时，第一次请求可能已经创建成功，label 被占用
            if (stripos($e->getMessage(), 'label') !== false && stripos($e->getMessage(), 'unique') !== false) {
                $existing = $this->findByNodeId($spec->region, $nodeId);
                if ($existing !== null) {
                    return $existing;
                }
            }
            // 重试用尽后仍是限流或 5xx：之前某次请求可能已经建出实例（label 只在本账号内去重）
            $status = (int) $e->getErrorCode();
            if ($status === 429 || $status >= 500) {
                throw new OutcomeUnknownException("Linode create outcome unknown: {$e->getMessage()}", 0, $e);
            }
            throw $e;
        }
    }

    public function get(string $region, string $instanceId): ?Instance
    {
        try {
            return $this->toInstance($this->client->request('GET', '/linode/instances/' . rawurlencode($instanceId)));
        } catch (ProviderException $e) {
            if ($e->getErrorCode() === '404') {
                return null;
            }
            throw $e;
        }
    }

    public function findByNodeId(string $region, string $nodeId): ?Instance
    {
        $items = $this->client->listAll('/linode/instances', ['label' => $nodeId]);
        return $items === [] ? null : $this->toInstance($items[0]);
    }

    public function listManaged(string $service, array $regions): array
    {
        $items = $this->client->listAll('/linode/instances', ['tags' => ProviderInterface::TAG_SERVICE . '=' . $service]);
        return array_map([$this, 'toInstance'], $items);
    }

    public function terminate(string $region, string $instanceId): void
    {
        try {
            $this->client->request('DELETE', '/linode/instances/' . rawurlencode($instanceId));
        } catch (ProviderException $e) {
            if ($e->getErrorCode() !== '404') {
                throw $e;
            }
        }
    }

    public function toInstance(array $data): Instance
    {
        $ipv4 = null;
        foreach ($data['ipv4'] ?? [] as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                $ipv4 = $address;
                break;
            }
        }
        $ipv6 = null;
        if (!empty($data['ipv6'])) {
            $ipv6 = explode('/', (string) $data['ipv6'])[0];
        }

        $tags = [];
        foreach ($data['tags'] ?? [] as $tag) {
            $parts = explode('=', (string) $tag, 2);
            $tags[$parts[0]] = $parts[1] ?? '';
        }
        $created = isset($data['created']) ? strtotime($data['created'] . ' UTC') : false;

        return new Instance(
            $this->name,
            (string) $data['id'],
            (string) ($data['region'] ?? ''),
            self::STATUS_MAP[$data['status'] ?? ''] ?? Instance::PENDING,
            $ipv4,
            $ipv6,
            isset($data['label']) ? (string) $data['label'] : null,
            $tags,
            $created === false ? null : $created
        );
    }

    /**
     * @param array<string,string> $tags
     * @param string[] $extra
     * @return string[]
     */
    private static function tagList(array $tags, array $extra): array
    {
        $list = [];
        foreach ($tags as $key => $value) {
            $list[] = "{$key}={$value}";
        }
        return array_values(array_unique(array_merge($list, $extra)));
    }

    private static function randomPassword(): string
    {
        // 包含大小写字母、数字和符号，满足 Linode 的密码强度要求
        return 'Aa1!' . strtr(base64_encode(random_bytes(30)), '+/', '-_');
    }
}
