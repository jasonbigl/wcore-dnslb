<?php

namespace Wcore\Dnslb\Aws;

use Wcore\Dnslb\Http\Request;

/**
 * AWS Signature Version 4 签名。
 *
 * @see https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv-create-signed-request.html
 */
final class SigV4Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private Credentials $credentials;

    public function __construct(Credentials $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * 返回加上 Host、X-Amz-Date、Authorization 头的新请求，不修改原请求。
     */
    public function sign(Request $request, string $service, string $region, int $timestamp): Request
    {
        $signed = clone $request;
        $parts = parse_url($request->url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("Invalid URL: {$request->url}");
        }

        $amzDate = gmdate('Ymd\THis\Z', $timestamp);
        $date = substr($amzDate, 0, 8);

        // 重签时（例如重试）去掉上一次签名留下的头
        $headers = [];
        foreach ($signed->headers as $name => $value) {
            if (strcasecmp($name, 'Authorization') !== 0 && strcasecmp($name, 'X-Amz-Date') !== 0) {
                $headers[$name] = $value;
            }
        }
        if ($signed->header('Host') === null) {
            $host = $parts['host'];
            if (isset($parts['port'])) {
                $host .= ':' . $parts['port'];
            }
            $headers['Host'] = $host;
        }
        $headers['X-Amz-Date'] = $amzDate;

        $canonicalHeaders = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders[strtolower($name)] = preg_replace('/\s+/', ' ', trim($value));
        }
        ksort($canonicalHeaders, SORT_STRING);
        $signedHeaders = implode(';', array_keys($canonicalHeaders));

        $canonicalRequest = implode("\n", [
            $request->method,
            self::canonicalUri($parts['path'] ?? '/'),
            self::canonicalQuery($parts['query'] ?? ''),
            self::joinHeaders($canonicalHeaders),
            $signedHeaders,
            hash('sha256', $request->body),
        ]);

        $scope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $key = hash_hmac('sha256', $date, 'AWS4' . $this->credentials->secretAccessKey, true);
        $key = hash_hmac('sha256', $region, $key, true);
        $key = hash_hmac('sha256', $service, $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);
        $signature = hash_hmac('sha256', $stringToSign, $key);

        $headers['Authorization'] = sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->credentials->accessKeyId,
            $scope,
            $signedHeaders,
            $signature
        );
        $signed->headers = $headers;
        return $signed;
    }

    /**
     * 非 S3 服务：每个路径段再做一次 URI 编码。
     */
    private static function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', $path);
        return implode('/', array_map('rawurlencode', $segments));
    }

    private static function canonicalQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $kv = explode('=', $pair, 2);
            $pairs[] = [
                rawurlencode(rawurldecode($kv[0])),
                rawurlencode(rawurldecode($kv[1] ?? '')),
            ];
        }
        usort($pairs, static function (array $a, array $b): int {
            return strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]);
        });
        return implode('&', array_map(static function (array $pair): string {
            return $pair[0] . '=' . $pair[1];
        }, $pairs));
    }

    /**
     * @param array<string,string> $headers
     */
    private static function joinHeaders(array $headers): string
    {
        $lines = '';
        foreach ($headers as $name => $value) {
            $lines .= "{$name}:{$value}\n";
        }
        return $lines;
    }
}
