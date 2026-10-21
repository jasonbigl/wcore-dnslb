<?php

namespace Wcore\Dnslb\Provider;

/**
 * 建机请求的结果未知：重试用尽后仍是服务端错误或限流，之前某次请求可能已经生效（例如响应超时但实例已创建）。
 * 调用方应与网络错误一样处理：不换建机方式，稍后按节点 ID 查找确认。
 */
final class OutcomeUnknownException extends \RuntimeException
{
}
