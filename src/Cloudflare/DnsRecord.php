<?php

namespace Wcore\Dnslb\Cloudflare;

/**
 * Cloudflare 的一条 DNS 记录（只处理 dnslb 用到的字段）。
 */
final class DnsRecord
{
    /** 自动 TTL（代理记录只能使用自动） */
    public const TTL_AUTO = 1;

    /** 现有记录的 ID；新记录为 null */
    public ?string $id = null;
    public string $type;
    public string $name;
    public string $content;
    public bool $proxied = false;
    public int $ttl = self::TTL_AUTO;
    public ?string $comment = null;

    public function __construct(string $type, string $name, string $content, bool $proxied = false, int $ttl = self::TTL_AUTO, ?string $comment = null)
    {
        $this->type = strtoupper($type);
        $this->name = strtolower(rtrim($name, '.'));
        $this->content = $content;
        $this->proxied = $proxied;
        $this->ttl = $ttl;
        $this->comment = $comment;
    }

    public static function fromApi(array $data): self
    {
        $record = new self(
            (string) ($data['type'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['content'] ?? ''),
            (bool) ($data['proxied'] ?? false),
            (int) ($data['ttl'] ?? self::TTL_AUTO),
            isset($data['comment']) && $data['comment'] !== '' ? (string) $data['comment'] : null
        );
        $record->id = isset($data['id']) ? (string) $data['id'] : null;
        return $record;
    }

    /**
     * 新建 / 修改记录时提交的字段。
     */
    public function toApi(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'content' => $this->content,
            'proxied' => $this->proxied,
            'ttl' => $this->ttl,
            'comment' => $this->comment ?? '',
        ];
    }

    /**
     * 同一名称下记录的唯一键：类型 + 地址（IPv6 按规范形式比较）。
     */
    public function key(): string
    {
        return $this->type . ' ' . self::normalizeAddress($this->content);
    }

    /**
     * 除 ID 外内容是否一致。
     */
    public function sameAs(self $other): bool
    {
        return $this->key() === $other->key()
            && $this->name === $other->name
            && $this->proxied === $other->proxied
            && $this->ttl === $other->ttl
            && $this->comment === $other->comment;
    }

    public static function normalizeAddress(string $value): string
    {
        $packed = @inet_pton($value);
        return $packed === false ? strtolower($value) : (string) inet_ntop($packed);
    }
}
