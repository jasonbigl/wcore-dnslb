<?php

namespace Wcore\Dnslb\Config;

final class HeartbeatConfig
{
    /** URL 和 body 中可用 {{service}}、{{cmd}} 占位符 */
    public string $url;
    public string $method;
    public ?string $username = null;
    public ?string $password = null;
    /** @var array<string,string> */
    public array $headers = [];
    public ?string $body;
    public float $timeout;

    public static function fromSection(Section $s): self
    {
        $s->allowOnly(['url', 'method', 'auth', 'headers', 'body', 'timeout']);
        $c = new self();
        $c->url = $s->string('url', Section::REQUIRED, '#^https?://#');
        $c->method = strtoupper($s->string('method', 'GET'));
        if (!in_array($c->method, ['GET', 'POST', 'PUT', 'HEAD'], true)) {
            $s->error('method', 'must be GET, POST, PUT or HEAD');
        }
        $auth = $s->raw('auth');
        if ($auth !== null) {
            if (!is_array($auth) || count($auth) !== 2 || !is_string($auth[0] ?? null) || !is_string($auth[1] ?? null)) {
                $s->error('auth', 'must be [username, password]');
            } else {
                [$c->username, $c->password] = $auth;
            }
        }
        $headers = $s->section('headers');
        if ($headers !== null) {
            foreach ($headers->toArray() as $name => $value) {
                if (!is_string($value)) {
                    $headers->error((string) $name, 'must be a string');
                    continue;
                }
                $c->headers[(string) $name] = $value;
            }
        }
        $c->body = $s->optionalString('body');
        $c->timeout = $s->float('timeout', 10, 1, 60);
        return $c;
    }
}
