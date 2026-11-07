<?php

namespace Wcore\Dnslb\Lifecycle;

/**
 * 预热失败节点的串口输出，保存在状态库旁边的目录里（例如 /var/lib/dnslb/web.sqlite.console/），只保留最近 KEEP 份。
 * 串口输出可能含有开机脚本打印的敏感信息，所以只写本地文件（目录 0700、文件 0600），不放进邮件。
 */
final class ConsoleArchive
{
    public const KEEP = 50;

    private ?string $dir;

    /**
     * @param string|null $dir 为 null 时不保存（状态库在内存中时）
     */
    public function __construct(?string $dir)
    {
        $this->dir = $dir;
    }

    public function enabled(): bool
    {
        return $this->dir !== null;
    }

    /**
     * @return string 保存的文件路径
     * @throws \RuntimeException 无法写入
     */
    public function save(string $nodeId, string $instanceId, string $output, int $now): string
    {
        if ($this->dir === null) {
            throw new \LogicException('Console archive is disabled');
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Cannot create directory {$this->dir}");
        }
        $file = sprintf(
            '%s/%s-%s-%s.log',
            $this->dir,
            gmdate('Ymd\THis\Z', $now),
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $nodeId),
            preg_replace('/[^A-Za-z0-9_.-]/', '_', $instanceId)
        );
        // 去掉终端颜色和回车，方便直接 grep
        $text = str_replace("\r", '', (string) preg_replace('/\e\[[0-9;?]*[A-Za-z]/', '', $output));
        if (@file_put_contents($file, $text, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write {$file}");
        }
        @chmod($file, 0600);
        $this->prune();
        return $file;
    }

    /**
     * 文件名以时间开头，按名称倒序保留最近的 KEEP 份。
     */
    private function prune(): void
    {
        $files = glob($this->dir . '/*.log') ?: [];
        rsort($files);
        foreach (array_slice($files, self::KEEP) as $old) {
            @unlink($old);
        }
    }
}
