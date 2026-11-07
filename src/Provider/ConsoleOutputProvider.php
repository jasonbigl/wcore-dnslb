<?php

namespace Wcore\Dnslb\Provider;

use Wcore\Dnslb\Http\HttpException;

/**
 * 能读取实例串口输出的云厂商（目前只有 EC2；Linode 没有对应接口）。
 * 节点预热失败时，删除实例之前保存一份，用来排查开机脚本（cloud-init）卡在哪里。
 */
interface ConsoleOutputProvider
{
    /**
     * @return string|null 串口输出（最近约 64 KB）；还没有输出时为 null
     * @throws ProviderException|HttpException
     */
    public function consoleOutput(string $region, string $instanceId): ?string;
}
