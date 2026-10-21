<?php

namespace Wcore\Dnslb\Dns;

/**
 * 目标域名下存在不由 dnslb 管理的 A / AAAA 记录，无法安全同步。
 */
final class DnsConflictException extends \RuntimeException
{
}
