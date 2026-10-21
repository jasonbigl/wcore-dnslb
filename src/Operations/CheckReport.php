<?php

namespace Wcore\Dnslb\Operations;

/**
 * Dnslb::check() 的结果。
 */
final class CheckReport
{
    /** @var CheckItem[] 按检查顺序排列 */
    public array $items = [];

    public function add(string $status, string $name, string $message): void
    {
        $this->items[] = new CheckItem($status, $name, $message);
    }

    /**
     * 没有失败项（警告不影响）。
     */
    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /**
     * @return CheckItem[]
     */
    public function failures(): array
    {
        return $this->withStatus(CheckItem::FAIL);
    }

    /**
     * @return CheckItem[]
     */
    public function warnings(): array
    {
        return $this->withStatus(CheckItem::WARN);
    }

    /**
     * @return CheckItem[]
     */
    private function withStatus(string $status): array
    {
        return array_values(array_filter($this->items, static function (CheckItem $item) use ($status): bool {
            return $item->status === $status;
        }));
    }
}
