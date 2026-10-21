<?php

namespace Wcore\Dnslb\Operations;

/**
 * 操作无法执行，例如节点不存在、节点不在线、替补建机失败。
 */
final class OperationException extends \RuntimeException
{
}
