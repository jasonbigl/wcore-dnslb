<?php

namespace Wcore\Dnslb\Config;

/**
 * 读取并校验配置数组中的一段。错误不立即抛出，而是收集起来，
 * 最后由 Config 一次性报告全部问题；出错的项返回占位值。
 */
final class Section
{
    /** 作为默认值传入时表示该项必填 */
    public const REQUIRED = "\0required";

    private array $data;
    private string $path;
    private ErrorBag $errors;

    public function __construct(array $data, string $path, ErrorBag $errors)
    {
        $this->data = $data;
        $this->path = $path;
        $this->errors = $errors;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data) && $this->data[$key] !== null;
    }

    /**
     * @return mixed
     */
    public function raw(string $key)
    {
        return $this->data[$key] ?? null;
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * 用新数据创建同路径、共用错误收集的 Section，例如校验合并后的配置。
     */
    public function withData(array $data): Section
    {
        return new Section($data, $this->path, $this->errors);
    }

    public function path(?string $key = null): string
    {
        if ($key === null) {
            return $this->path;
        }
        return $this->path === '' ? $key : "{$this->path}.{$key}";
    }

    public function error(?string $key, string $message): void
    {
        $this->errors->add($this->path($key) . ': ' . $message);
    }

    /**
     * 不允许出现未知配置项，防止拼写错误被静默忽略。
     *
     * @param string[] $keys
     */
    public function allowOnly(array $keys): void
    {
        foreach (array_keys($this->data) as $key) {
            if (!in_array($key, $keys, true)) {
                $this->error((string) $key, 'unknown option (allowed: ' . implode(', ', $keys) . ')');
            }
        }
    }

    /**
     * @param string|null $default 传 Section::REQUIRED 表示必填
     */
    public function string(string $key, ?string $default = self::REQUIRED, ?string $pattern = null): string
    {
        $value = $this->optionalString($key, $pattern);
        if ($value !== null) {
            return $value;
        }
        if ($default === self::REQUIRED) {
            if (!$this->has($key)) {
                $this->error($key, 'is required');
            }
            return '';
        }
        return (string) $default;
    }

    public function optionalString(string $key, ?string $pattern = null): ?string
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->data[$key];
        if (!is_string($value) || $value === '') {
            $this->error($key, 'must be a non-empty string');
            return null;
        }
        if ($pattern !== null && !preg_match($pattern, $value)) {
            $this->error($key, "invalid value \"{$value}\"");
            return null;
        }
        return $value;
    }

    /**
     * 凭证类字符串（密钥、token）：不能含空白或控制字符。出错时不回显值。
     *
     * @param bool $required 为 false 时不存在返回 null
     */
    public function secret(string $key, bool $required = true): ?string
    {
        if (!$this->has($key)) {
            if ($required) {
                $this->error($key, 'is required');
                return '';
            }
            return null;
        }
        $value = $this->data[$key];
        if (!is_string($value) || $value === '') {
            $this->error($key, 'must be a non-empty string');
            return '';
        }
        if (preg_match('/[\s\x00-\x1f\x7f]/', $value)) {
            $this->error($key, 'contains whitespace or control characters (check for Windows line endings or stray spaces when loading it from a file / env var)');
            return '';
        }
        return $value;
    }

    /**
     * @param int|string $default 传 Section::REQUIRED 表示必填
     */
    public function int(string $key, $default = self::REQUIRED, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int
    {
        $value = $this->optionalInt($key, $min, $max);
        if ($value !== null) {
            return $value;
        }
        if ($default === self::REQUIRED) {
            if (!$this->has($key)) {
                $this->error($key, 'is required');
            }
            return max($min, 0);
        }
        return (int) $default;
    }

    public function optionalInt(string $key, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): ?int
    {
        if (!$this->has($key)) {
            return null;
        }
        $value = $this->data[$key];
        if (!is_int($value)) {
            $this->error($key, 'must be an integer');
            return null;
        }
        if ($value < $min || $value > $max) {
            $this->error($key, "must be between {$min} and {$max}");
            return null;
        }
        return $value;
    }

    /**
     * @param float|string $default 传 Section::REQUIRED 表示必填
     */
    public function float(string $key, $default = self::REQUIRED, float $min = -INF, float $max = INF): float
    {
        if (!$this->has($key)) {
            if ($default === self::REQUIRED) {
                $this->error($key, 'is required');
                return 0.0;
            }
            return (float) $default;
        }
        $value = $this->data[$key];
        if (!is_int($value) && !is_float($value)) {
            $this->error($key, 'must be a number');
            return 0.0;
        }
        if ($value < $min || $value > $max) {
            $this->error($key, "must be between {$min} and {$max}");
            return 0.0;
        }
        return (float) $value;
    }

    public function bool(string $key, bool $default): bool
    {
        if (!$this->has($key)) {
            return $default;
        }
        if (!is_bool($this->data[$key])) {
            $this->error($key, 'must be true or false');
            return $default;
        }
        return $this->data[$key];
    }

    /**
     * @param string[] $allowed
     */
    public function enum(string $key, array $allowed, ?string $default = self::REQUIRED): string
    {
        $value = $this->string($key, $default);
        if ($value !== '' && !in_array($value, $allowed, true)) {
            $this->error($key, "must be one of: " . implode(', ', $allowed));
            return $allowed[0];
        }
        return $value;
    }

    /**
     * 字符串列表。
     *
     * @param string[]|null $default null 表示必填
     * @return string[]
     */
    public function stringList(string $key, ?array $default = null, bool $allowEmpty = false): array
    {
        if (!$this->has($key)) {
            if ($default === null) {
                $this->error($key, 'is required');
                return [];
            }
            return $default;
        }
        $value = $this->data[$key];
        if (!is_array($value) || !self::isList($value)) {
            $this->error($key, 'must be a list');
            return [];
        }
        foreach ($value as $i => $item) {
            if (!is_string($item) || $item === '') {
                $this->error("{$key}.{$i}", 'must be a non-empty string');
                return [];
            }
        }
        if (!$allowEmpty && $value === []) {
            $this->error($key, 'must not be empty');
        }
        return $value;
    }

    /**
     * 子段（关联数组）。不存在时：必填则报错，否则返回 null。
     */
    public function section(string $key, bool $required = false): ?Section
    {
        if (!$this->has($key)) {
            if ($required) {
                $this->error($key, 'is required');
            }
            return null;
        }
        $value = $this->data[$key];
        if (!is_array($value) || ($value !== [] && self::isList($value))) {
            $this->error($key, 'must be an associative array');
            return null;
        }
        return new Section($value, $this->path($key), $this->errors);
    }

    /**
     * 同 section()，但不存在时返回空的子段，方便统一读取默认值。
     */
    public function sectionOrEmpty(string $key, bool $required = false): Section
    {
        return $this->section($key, $required) ?? new Section([], $this->path($key), $this->errors);
    }

    /**
     * 以名称为键的子段集合，例如 pools、providers。值为 null 的项会被跳过。
     *
     * @return array<string,Section>
     */
    public function namedSections(string $key, bool $required = false): array
    {
        $map = $this->section($key, $required);
        if ($map === null) {
            return [];
        }
        $result = [];
        foreach ($map->toArray() as $name => $value) {
            if ($value === null) {
                continue;
            }
            $child = $map->section((string) $name, true);
            if ($child !== null) {
                $result[(string) $name] = $child;
            }
        }
        if ($required && $result === []) {
            $this->error($key, 'must not be empty');
        }
        return $result;
    }

    /**
     * 子段列表，例如 launch。
     *
     * @return Section[]
     */
    public function sectionList(string $key): array
    {
        if (!$this->has($key)) {
            $this->error($key, 'is required');
            return [];
        }
        $value = $this->data[$key];
        if (!is_array($value) || !self::isList($value) || $value === []) {
            $this->error($key, 'must be a non-empty list');
            return [];
        }
        $result = [];
        foreach ($value as $i => $item) {
            if (!is_array($item) || ($item !== [] && self::isList($item))) {
                $this->error("{$key}.{$i}", 'must be an associative array');
                continue;
            }
            $result[] = new Section($item, $this->path("{$key}.{$i}"), $this->errors);
        }
        return $result;
    }

    private static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }
}
