<?php

namespace Wcore\Dnslb\Cloudflare;

use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Support\Clock;

/**
 * Cloudflare API v4 的 JSON 客户端（只用到 DNS 记录和区域接口）。
 *
 * 对 429、5xx 和网络错误退避重试。批量修改记录在结果未知时重试也是安全的：
 * Cloudflare 不允许创建完全相同的记录，重复提交只会报错，下一次同步会按实际记录重新比对。
 */
final class CloudflareClient
{
    public const API_URL = 'https://api.cloudflare.com/client/v4';

    private const PAGE_SIZE = 1000;
    private const BACKOFF_BASE_US = 500000;
    private const BACKOFF_CAP_US = 8000000;

    private Transport $transport;
    private string $token;
    private Clock $clock;
    private string $apiUrl;
    private int $maxAttempts;

    public function __construct(Transport $transport, string $token, Clock $clock, string $apiUrl = self::API_URL, int $maxAttempts = 4)
    {
        $this->transport = $transport;
        $this->token = $token;
        $this->clock = $clock;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * @return array{id: string, name: string, status: string}
     * @throws CloudflareException|HttpException
     */
    public function getZone(string $zoneId): array
    {
        $zone = $this->request('GET', '/zones/' . rawurlencode($zoneId))['result'] ?? [];
        return [
            'id' => (string) ($zone['id'] ?? $zoneId),
            'name' => (string) ($zone['name'] ?? ''),
            'status' => (string) ($zone['status'] ?? ''),
        ];
    }

    /**
     * 某个名称下的全部记录（所有类型）。
     *
     * @return DnsRecord[]
     * @throws CloudflareException|HttpException
     */
    public function listRecords(string $zoneId, string $name): array
    {
        $records = [];
        $page = 1;
        do {
            $data = $this->request('GET', '/zones/' . rawurlencode($zoneId) . '/dns_records?' . http_build_query([
                'name.exact' => $name,
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
            ]));
            foreach ($data['result'] ?? [] as $item) {
                $records[] = DnsRecord::fromApi($item);
            }
            $pages = (int) ($data['result_info']['total_pages'] ?? 1);
            $page++;
        } while ($page <= $pages);
        return $records;
    }

    /**
     * 在一个事务中批量修改记录。Cloudflare 的执行顺序固定为：删除 → 修改 → 新增；任何一项失败则整批不生效。
     *
     * @param DnsRecord[] $deletes 带 id 的现有记录
     * @param DnsRecord[] $patches 带 id、内容为新值的记录
     * @param DnsRecord[] $posts   新记录
     * @throws CloudflareException|HttpException
     */
    public function batch(string $zoneId, array $deletes, array $patches, array $posts): void
    {
        $body = [];
        if ($deletes !== []) {
            $body['deletes'] = array_map(static function (DnsRecord $record): array {
                return ['id' => (string) $record->id];
            }, $deletes);
        }
        if ($patches !== []) {
            $body['patches'] = array_map(static function (DnsRecord $record): array {
                return ['id' => (string) $record->id] + $record->toApi();
            }, $patches);
        }
        if ($posts !== []) {
            $body['posts'] = array_map(static function (DnsRecord $record): array {
                return $record->toApi();
            }, $posts);
        }
        if ($body !== []) {
            $this->request('POST', '/zones/' . rawurlencode($zoneId) . '/dns_records/batch', $body);
        }
    }

    /**
     * @return array 完整的响应（result、result_info 等）
     * @throws CloudflareException|HttpException
     */
    public function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        $payload = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        for ($attempt = 1; ; $attempt++) {
            $wait = null;
            try {
                $response = $this->transport->send(new Request($method, $this->apiUrl . $path, $headers, $payload));
                $data = json_decode($response->body, true);
                if ($response->isSuccessful() && is_array($data) && ($data['success'] ?? false) === true) {
                    return $data;
                }
                $error = self::error($method, $path, $response, is_array($data) ? $data : null);
                if ($response->status !== 429 && $response->status < 500) {
                    throw $error;
                }
                $retryAfter = $response->header('Retry-After');
                if ($retryAfter !== null && ctype_digit($retryAfter)) {
                    $wait = min((int) $retryAfter, 30) * 1000000;
                }
            } catch (HttpException $e) {
                $error = $e;
            }
            if ($attempt >= $this->maxAttempts) {
                throw $error;
            }
            $this->clock->sleep($wait ?? $this->backoff($attempt));
        }
    }

    private function backoff(int $attempt): int
    {
        $ceiling = (int) min(self::BACKOFF_CAP_US, self::BACKOFF_BASE_US * (2 ** ($attempt - 1)));
        return random_int(intdiv($ceiling, 2), $ceiling);
    }

    /**
     * Cloudflare 错误格式：{"success": false, "errors": [{"code": 6003, "message": "...", "error_chain": [{"code": 6111, ...}]}]}
     */
    private static function error(string $method, string $path, Response $response, ?array $data): CloudflareException
    {
        $codes = [];
        $messages = [];
        foreach ($data['errors'] ?? [] as $error) {
            $code = (int) ($error['code'] ?? 0);
            $codes[] = $code;
            $message = trim("{$code} " . ($error['message'] ?? ''));
            $causes = array_map(static function (array $cause): string {
                return trim(($cause['code'] ?? '') . ' ' . ($cause['message'] ?? ''));
            }, array_filter($error['error_chain'] ?? [], 'is_array'));
            $messages[] = $causes === [] ? $message : $message . '（' . implode('；', $causes) . '）';
        }
        $detail = $messages !== [] ? implode('; ', $messages) : "HTTP {$response->status}";
        $path = (string) strtok($path, '?');
        return new CloudflareException("Cloudflare API error ({$method} {$path}, HTTP {$response->status}): {$detail}", $response->status, $codes);
    }
}
