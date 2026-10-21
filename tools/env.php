<?php

/**
 * 读取验证脚本用的凭证：优先使用环境变量，未设置时读取仓库根目录的 .env（每行 KEY=VALUE，# 开头为注释）。
 */
function dnslb_env(string $name): ?string
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }

    static $file = null;
    if ($file === null) {
        $file = [];
        $path = __DIR__ . '/../.env';
        foreach (is_readable($path) ? (array) file($path, FILE_IGNORE_NEW_LINES) : [] as $line) {
            // 行尾空白（包括 Windows 换行的 \r）一并去掉
            if (!preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*$/', (string) $line, $m)) {
                continue;
            }
            $v = $m[2];
            if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) {
                $v = substr($v, 1, -1);
            }
            $file[$m[1]] = $v;
        }
    }
    return ($file[$name] ?? '') !== '' ? $file[$name] : null;
}
