<?php

namespace Wcore\Dnslb\Provider\Linode;

use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Provider\ProviderException;
use Wcore\Dnslb\Support\Clock;

/**
 * Linode API v4 的 JSON 客户端。对 429 和 5xx 做退避重试，网络错误也重试
 * （创建实例时用唯一的 label 防止重复创建，所以重试是安全的）。
 */
final class LinodeClient
{
    private const PAGE_SIZE = 500;
    private const BACKOFF_BASE_US = 500000;
    private const BACKOFF_CAP_US = 8000000;

    private Transport $transport;
    private string $token;
    private string $apiUrl;
    private Clock $clock;
    private int $maxAttempts;

    public function __construct(Transport $transport, string $token, string $apiUrl, Clock $clock, int $maxAttempts = 4)
    {
        $this->transport = $transport;
        $this->token = $token;
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->clock = $clock;
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * @param array|null $filter X-Filter 过滤条件，例如 ['tags' => 'dnslb:service=web']
     * @throws ProviderException|HttpException
     */
    public function request(string $method, string $path, ?array $body = null, ?array $filter = null): array
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Accept' => 'application/json'];
        $payload = '';
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $payload = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if ($filter !== null) {
            $headers['X-Filter'] = (string) json_encode($filter, JSON_UNESCAPED_SLASHES);
        }

        for ($attempt = 1; ; $attempt++) {
            $wait = null;
            try {
                $response = $this->transport->send(new Request($method, $this->apiUrl . $path, $headers, $payload));
                if ($response->isSuccessful()) {
                    $data = $response->body === '' ? [] : $response->json();
                    return is_array($data) ? $data : [];
                }
                $error = self::error($response);
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

    /**
     * 读取分页列表的全部数据。
     *
     * @throws ProviderException|HttpException
     */
    public function listAll(string $path, ?array $filter = null): array
    {
        $items = [];
        $page = 1;
        do {
            $separator = strpos($path, '?') === false ? '?' : '&';
            $data = $this->request('GET', "{$path}{$separator}page={$page}&page_size=" . self::PAGE_SIZE, null, $filter);
            foreach ($data['data'] ?? [] as $item) {
                $items[] = $item;
            }
            $pages = (int) ($data['pages'] ?? 1);
            $page++;
        } while ($page <= $pages);
        return $items;
    }

    private function backoff(int $attempt): int
    {
        $ceiling = (int) min(self::BACKOFF_CAP_US, self::BACKOFF_BASE_US * (2 ** ($attempt - 1)));
        return random_int(intdiv($ceiling, 2), $ceiling);
    }

    /**
     * Linode 错误格式：{"errors": [{"reason": "...", "field": "..."}]}
     */
    private static function error(Response $response): ProviderException
    {
        $data = json_decode($response->body, true);
        $reasons = [];
        foreach (is_array($data) ? ($data['errors'] ?? []) : [] as $error) {
            $reason = (string) ($error['reason'] ?? '');
            if (isset($error['field'])) {
                $reason = "{$error['field']}: {$reason}";
            }
            $reasons[] = $reason;
        }
        $message = $reasons !== [] ? implode('; ', $reasons) : "HTTP {$response->status}";
        return new ProviderException("Linode API error ({$response->status}): {$message}", (string) $response->status);
    }
}
