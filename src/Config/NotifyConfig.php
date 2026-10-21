<?php

namespace Wcore\Dnslb\Config;

final class NotifyConfig
{
    public const DRIVER_SES = 'ses';

    public string $driver;
    public string $region;
    public string $from;
    /** @var string[] */
    public array $to;
    /** 同一告警的最小发送间隔（秒） */
    public int $throttle;
    public string $subjectPrefix;

    public static function fromSection(Section $s, string $service): self
    {
        $s->allowOnly(['driver', 'region', 'from', 'to', 'throttle', 'subject_prefix']);
        $c = new self();
        $c->driver = $s->enum('driver', [self::DRIVER_SES], self::DRIVER_SES);
        $c->region = $s->string('region', Section::REQUIRED, '/^[a-z]{2}(-gov)?-[a-z]+-\d+$/');
        $c->from = $s->string('from', Section::REQUIRED, '/@/');
        $c->to = $s->stringList('to');
        $c->throttle = $s->int('throttle', 3600, 0);
        $c->subjectPrefix = $s->string('subject_prefix', "[dnslb:{$service}]");
        return $c;
    }
}
