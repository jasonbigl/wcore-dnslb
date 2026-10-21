<?php

namespace Wcore\Dnslb\Config;

use Wcore\Dnslb\Lifecycle\UserDataRenderer;

final class UserDataConfig
{
    /** 模板文件的绝对路径 */
    public string $template;
    /** @var array<string,string> 自定义占位符 */
    public array $vars = [];

    public static function fromSection(Section $s, string $baseDir): self
    {
        $s->allowOnly(['template', 'vars']);
        $c = new self();
        $c->template = Config::resolvePath($s->string('template'), $baseDir);
        if ($s->has('template') && !is_readable($c->template)) {
            $s->error('template', "file not readable: {$c->template}");
        }
        $vars = $s->section('vars');
        if ($vars !== null) {
            foreach ($vars->toArray() as $key => $value) {
                if (!preg_match('/^[a-z0-9_]+$/i', (string) $key)) {
                    $vars->error((string) $key, 'variable names may only contain letters, digits and underscores');
                } elseif (in_array($key, UserDataRenderer::BUILTINS, true)) {
                    $vars->error((string) $key, 'is a built-in placeholder and cannot be overridden');
                } elseif (!is_scalar($value)) {
                    $vars->error((string) $key, 'must be a scalar value');
                } else {
                    $c->vars[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
                }
            }
        }

        if ($s->has('template') && is_readable($c->template)) {
            $known = array_merge(UserDataRenderer::BUILTINS, array_keys($c->vars));
            foreach (UserDataRenderer::placeholders((string) file_get_contents($c->template)) as $name) {
                if (!in_array($name, $known, true)) {
                    $s->error('template', "unknown placeholder {{{$name}}} (built-in: " . implode(', ', UserDataRenderer::BUILTINS) . '; others must be defined in user_data.vars)');
                }
            }
        }
        return $c;
    }
}
