<?php

namespace Wcore\Dnslb\Config;

/**
 * 池中一种建机方式。一个池可以有多种，按顺序尝试。
 */
final class LaunchSpec
{
    /** 在池 launch 列表中的位置，0 为首选 */
    public int $index;
    /** providers 中的名称 */
    public string $provider;
    public string $driver;
    /** 云厂商的区域 / 机房 */
    public string $region;
    /** 加权路由模式下的权重 */
    public int $weight;
    /** 云厂商专属参数，键名见 fromSection() */
    public array $options = [];

    /**
     * @param array<string,ProviderConfig> $providers
     */
    public static function fromSection(int $index, Section $s, array $providers): self
    {
        $c = new self();
        $c->index = $index;
        $c->provider = $s->string('provider');
        $c->region = $s->string('region', Section::REQUIRED, '/^[a-z0-9-]+$/');
        $c->weight = $s->int('weight', 10, 0, 255);

        $provider = $providers[$c->provider] ?? null;
        if ($provider === null) {
            if ($c->provider !== '') {
                $s->error('provider', "unknown provider \"{$c->provider}\"");
            }
            $c->driver = '';
            return $c;
        }
        $c->driver = $provider->driver;

        if ($c->driver === ProviderConfig::DRIVER_LINODE) {
            $s->allowOnly(['provider', 'region', 'weight', 'type', 'image', 'firewall_id', 'authorized_keys', 'private_ip', 'tags']);
            $c->options = [
                'type' => $s->string('type'),
                'image' => $s->string('image'),
                'firewall_id' => $s->optionalInt('firewall_id', 1),
                'authorized_keys' => $s->stringList('authorized_keys', [], true),
                'private_ip' => $s->bool('private_ip', false),
                'tags' => $s->stringList('tags', [], true),
            ];
        } else {
            $s->allowOnly([
                'provider', 'region', 'weight', 'instance_type', 'ami', 'subnet_id', 'security_group_ids',
                'key_name', 'volume_size', 'volume_type', 'iam_instance_profile', 'tags',
            ]);
            if ($s->has('region') && !preg_match('/^[a-z]{2}(-gov)?-[a-z]+-\d+$/', $c->region)) {
                $s->error('region', "invalid AWS region \"{$c->region}\"");
            }
            $tags = [];
            $tagSection = $s->section('tags');
            if ($tagSection !== null) {
                foreach ($tagSection->toArray() as $key => $value) {
                    if (!is_string($value)) {
                        $tagSection->error((string) $key, 'must be a string');
                        continue;
                    }
                    $tags[(string) $key] = $value;
                }
            }
            $c->options = [
                'instance_type' => $s->string('instance_type'),
                'ami' => $s->string('ami', Section::REQUIRED, '/^ami-[0-9a-f]+$/'),
                'subnet_id' => $s->string('subnet_id', Section::REQUIRED, '/^subnet-[0-9a-f]+$/'),
                'security_group_ids' => $s->stringList('security_group_ids', [], true),
                'key_name' => $s->optionalString('key_name'),
                'volume_size' => $s->optionalInt('volume_size', 1, 16384),
                'volume_type' => $s->optionalString('volume_type'),
                'iam_instance_profile' => $s->optionalString('iam_instance_profile'),
                'tags' => $tags,
            ];
        }
        return $c;
    }
}
