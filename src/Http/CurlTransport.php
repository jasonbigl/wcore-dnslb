<?php

namespace Wcore\Dnslb\Http;

final class CurlTransport implements Transport
{
    private const USER_AGENT = 'wcore-dnslb';

    public function send(Request $request): Response
    {
        $headers = [];
        $handle = $this->createHandle($request, $headers);
        try {
            $body = curl_exec($handle);
            if ($body === false) {
                throw new HttpException($this->describeError($request, curl_error($handle)));
            }
            return $this->buildResponse($handle, (string) $body, $headers);
        } finally {
            curl_close($handle);
        }
    }

    public function sendAll(array $requests, int $concurrency): array
    {
        $concurrency = max(1, $concurrency);
        $multi = curl_multi_init();
        $pending = $requests;
        $active = [];   // handleId => [key, handle]
        $headers = [];  // handleId => header array
        $results = [];

        try {
            while ($pending !== [] || $active !== []) {
                while ($pending !== [] && count($active) < $concurrency) {
                    $key = array_key_first($pending);
                    $request = $pending[$key];
                    unset($pending[$key]);
                    $headerBag = [];
                    $handle = $this->createHandle($request, $headerBag);
                    $id = self::handleId($handle);
                    $headers[$id] = &$headerBag;
                    unset($headerBag);
                    $active[$id] = [$key, $handle, $request];
                    curl_multi_add_handle($multi, $handle);
                }

                do {
                    $status = curl_multi_exec($multi, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);

                while (($info = curl_multi_info_read($multi)) !== false) {
                    $handle = $info['handle'];
                    $id = self::handleId($handle);
                    [$key, , $request] = $active[$id];
                    if ($info['result'] === CURLE_OK) {
                        $results[$key] = $this->buildResponse($handle, (string) curl_multi_getcontent($handle), $headers[$id]);
                    } else {
                        $error = curl_error($handle) ?: curl_strerror($info['result']);
                        $results[$key] = new HttpException($this->describeError($request, $error));
                    }
                    curl_multi_remove_handle($multi, $handle);
                    curl_close($handle);
                    unset($active[$id], $headers[$id]);
                }

                if ($active !== [] && curl_multi_select($multi, 1.0) === -1) {
                    usleep(1000);
                }
            }
        } finally {
            foreach ($active as [, $handle]) {
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);
        }

        $ordered = [];
        foreach ($requests as $key => $_) {
            $ordered[$key] = $results[$key];
        }
        return $ordered;
    }

    /**
     * @param array<string,string> $headers 用于接收响应头（引用）
     * @return resource|\CurlHandle
     */
    private function createHandle(Request $request, array &$headers)
    {
        $handle = curl_init();
        $requestHeaders = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $requestHeaders[] = "{$name}: {$value}";
        }

        $options = [
            CURLOPT_URL => $request->url,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_TIMEOUT_MS => (int) ($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($request->connectTimeout * 1000),
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => $request->verifyTls,
            CURLOPT_SSL_VERIFYHOST => $request->verifyTls ? 2 : 0,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                $trimmed = trim($line);
                if (stripos($trimmed, 'HTTP/') === 0) {
                    // 新的状态行（例如 100 Continue 之后），丢弃之前的头
                    $headers = [];
                } elseif (strpos($trimmed, ':') !== false) {
                    [$name, $value] = explode(':', $trimmed, 2);
                    $name = strtolower(trim($name));
                    $value = trim($value);
                    $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
                }
                return strlen($line);
            },
        ];
        if ($request->method === 'HEAD') {
            $options[CURLOPT_NOBODY] = true;
        } elseif ($request->body !== '' || in_array($request->method, ['POST', 'PUT', 'PATCH'], true)) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        if ($request->resolve !== []) {
            $options[CURLOPT_RESOLVE] = $request->resolve;
            // 连接池按主机名和端口复用连接，不区分固定的地址：否则健康检查可能复用别的节点的连接，探测错节点
            $options[CURLOPT_FRESH_CONNECT] = true;
            $options[CURLOPT_FORBID_REUSE] = true;
        }
        if ($request->ipVersion === 'v4') {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V4;
        } elseif ($request->ipVersion === 'v6') {
            $options[CURLOPT_IPRESOLVE] = CURL_IPRESOLVE_V6;
        }

        curl_setopt_array($handle, $options);
        return $handle;
    }

    /**
     * @param resource|\CurlHandle $handle
     * @param array<string,string> $headers
     */
    private function buildResponse($handle, string $body, array $headers): Response
    {
        return new Response(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $headers,
            $body,
            (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME)
        );
    }

    private function describeError(Request $request, string $error): string
    {
        $url = $request->url;
        $query = strpos($url, '?');
        if ($query !== false) {
            $url = substr($url, 0, $query);
        }
        return "{$request->method} {$url}: {$error}";
    }

    /**
     * PHP 7 的 curl 句柄是 resource，PHP 8 起是 CurlHandle 对象。
     *
     * @param resource|\CurlHandle $handle
     */
    private static function handleId($handle): int
    {
        return is_resource($handle) ? (int) $handle : spl_object_id($handle);
    }
}
