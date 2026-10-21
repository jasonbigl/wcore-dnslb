<?php

namespace Wcore\Dnslb\Config;

final class MetricSourceConfig
{
    public const DRIVER_PROMETHEUS = 'prometheus';

    public string $name;
    public string $driver;
    /** Prometheus HTTP API 的基础地址（不含 /api/v1/query） */
    public string $url;
    public ?string $username;
    public ?string $password;
    public float $timeout;

    public static function fromSection(string $name, Section $s): self
    {
        $s->allowOnly(['driver', 'url', 'username', 'password', 'timeout']);
        $c = new self();
        $c->name = $name;
        $c->driver = $s->enum('driver', [self::DRIVER_PROMETHEUS], self::DRIVER_PROMETHEUS);
        $c->url = rtrim($s->string('url', Section::REQUIRED, '#^https?://#'), '/');
        $c->username = $s->optionalString('username');
        $c->password = $s->secret('password', false);
        $c->timeout = $s->float('timeout', 15, 1, 120);
        return $c;
    }
}
