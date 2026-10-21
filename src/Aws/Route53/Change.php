<?php

namespace Wcore\Dnslb\Aws\Route53;

use Wcore\Dnslb\Dns\DnsChange;

final class Change implements DnsChange
{
    public const CREATE = 'CREATE';
    public const DELETE = 'DELETE';
    public const UPSERT = 'UPSERT';

    public string $action;
    public RecordSet $recordSet;

    public function __construct(string $action, RecordSet $recordSet)
    {
        if (!in_array($action, [self::CREATE, self::DELETE, self::UPSERT], true)) {
            throw new \InvalidArgumentException("Invalid change action: {$action}");
        }
        $this->action = $action;
        $this->recordSet = $recordSet;
    }

    public static function create(RecordSet $recordSet): self
    {
        return new self(self::CREATE, $recordSet);
    }

    public static function delete(RecordSet $recordSet): self
    {
        return new self(self::DELETE, $recordSet);
    }

    public static function upsert(RecordSet $recordSet): self
    {
        return new self(self::UPSERT, $recordSet);
    }

    /**
     * 便于日志阅读的描述，例如 "CREATE A tokyo.pool.app.example.com [web-tokyo-a1b2c3] 1.2.3.4"。
     */
    public function describe(): string
    {
        $r = $this->recordSet;
        $text = "{$this->action} {$r->type} {$r->name}";
        if ($r->setIdentifier !== null) {
            $text .= " [{$r->setIdentifier}]";
        }
        if ($r->region !== null) {
            $text .= " region={$r->region}";
        }
        if ($r->weight !== null) {
            $text .= " weight={$r->weight}";
        }
        if ($r->aliasTarget !== null) {
            return $text . " -> {$r->aliasTarget->dnsName}";
        }
        return $text . ' ' . implode(',', $r->values);
    }

    public function toXml(): string
    {
        return '<Change><Action>' . $this->action . '</Action>' . $this->recordSet->toXml() . '</Change>';
    }
}
