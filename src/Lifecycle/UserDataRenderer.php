<?php

namespace Wcore\Dnslb\Lifecycle;

use Wcore\Dnslb\Config\UserDataConfig;

/**
 * 渲染 user-data 模板，替换 {{name}} 占位符（名称只含字母、数字、下划线；
 * 像 cloud-init Jinja 模板里 {{ v1.instance_id }} 这种带点号的写法不受影响）。
 */
final class UserDataRenderer
{
    public const BUILTINS = ['node_id', 'service', 'pool', 'provider', 'region', 'latency_region'];
    private const PATTERN = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    private UserDataConfig $config;

    public function __construct(UserDataConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string,string> $builtins 内置占位符的值，键见 BUILTINS
     */
    public function render(array $builtins): string
    {
        $template = @file_get_contents($this->config->template);
        if ($template === false) {
            throw new \RuntimeException("Cannot read user-data template: {$this->config->template}");
        }
        $values = $builtins + $this->config->vars;
        return (string) preg_replace_callback(self::PATTERN, static function (array $m) use ($values): string {
            if (!array_key_exists($m[1], $values)) {
                throw new \RuntimeException("Unknown placeholder {{{$m[1]}}} in user-data template");
            }
            return $values[$m[1]];
        }, $template);
    }

    /**
     * 模板中用到的占位符名称。
     *
     * @return string[]
     */
    public static function placeholders(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);
        return array_values(array_unique($matches[1]));
    }
}
