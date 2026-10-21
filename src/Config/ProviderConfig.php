<?php

namespace Wcore\Dnslb\Config;

final class ProviderConfig
{
    public const DRIVER_LINODE = 'linode';
    public const DRIVER_EC2 = 'ec2';

    public string $name;
    public string $driver;
    /** Linode API token */
    public ?string $token = null;
    public ?string $apiUrl = null;

    public static function fromSection(string $name, Section $s): self
    {
        $c = new self();
        $c->name = $name;
        $c->driver = $s->enum('driver', [self::DRIVER_LINODE, self::DRIVER_EC2]);
        if ($c->driver === self::DRIVER_LINODE) {
            $s->allowOnly(['driver', 'token', 'api_url']);
            $c->token = $s->secret('token');
            $c->apiUrl = rtrim($s->string('api_url', 'https://api.linode.com/v4'), '/');
        } else {
            // EC2 使用顶层 aws 段的密钥
            $s->allowOnly(['driver']);
        }
        return $c;
    }
}
