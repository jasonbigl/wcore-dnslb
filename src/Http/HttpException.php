<?php

namespace Wcore\Dnslb\Http;

/**
 * 网络层错误：DNS 解析失败、连接失败、超时、TLS 错误等。HTTP 4xx/5xx 不属于此类。
 */
class HttpException extends \RuntimeException
{
}
