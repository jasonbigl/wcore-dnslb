<?php

namespace Wcore\Dnslb\Operations;

final class CheckItem
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** ok / warn / fail */
    public string $status;
    /** 检查项名称，例如 "Route 53 托管区域" */
    public string $name;
    /** 结果说明或错误信息 */
    public string $message;

    public function __construct(string $status, string $name, string $message)
    {
        $this->status = $status;
        $this->name = $name;
        $this->message = $message;
    }
}
