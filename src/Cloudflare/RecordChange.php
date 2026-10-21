<?php

namespace Wcore\Dnslb\Cloudflare;

use Wcore\Dnslb\Dns\DnsChange;

final class RecordChange implements DnsChange
{
    public const CREATE = 'CREATE';
    public const UPDATE = 'UPDATE';
    public const DELETE = 'DELETE';

    public string $action;
    /** 新建 / 修改为记录的新内容（修改时带现有记录的 id）；删除时为现有记录 */
    public DnsRecord $record;

    private function __construct(string $action, DnsRecord $record)
    {
        $this->action = $action;
        $this->record = $record;
    }

    public static function create(DnsRecord $record): self
    {
        return new self(self::CREATE, $record);
    }

    /**
     * @param DnsRecord $current 现有记录（提供 id）
     * @param DnsRecord $desired 新内容
     */
    public static function update(DnsRecord $current, DnsRecord $desired): self
    {
        $record = clone $desired;
        $record->id = $current->id;
        return new self(self::UPDATE, $record);
    }

    public static function delete(DnsRecord $record): self
    {
        return new self(self::DELETE, $record);
    }

    /**
     * 例如 "CREATE A *.example.com 1.2.3.4 proxied [dnslb node web-tokyo-a1b2c3]"。
     */
    public function describe(): string
    {
        $r = $this->record;
        $text = "{$this->action} {$r->type} {$r->name} {$r->content}" . ($r->proxied ? ' proxied' : " ttl={$r->ttl}");
        return $r->comment !== null ? "{$text} [{$r->comment}]" : $text;
    }
}
