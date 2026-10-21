<?php

namespace Wcore\Dnslb\Aws;

use Wcore\Dnslb\Http\HttpException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Http\Transport;
use Wcore\Dnslb\Support\Clock;

/**
 * 各 AWS 服务客户端的基类：签名、发送，对限流和临时故障做指数退避重试。
 */
abstract class AwsClient
{
    private const BACKOFF_BASE_US = 200000;
    private const BACKOFF_CAP_US = 5000000;

    private Transport $transport;
    private SigV4Signer $signer;
    private Clock $clock;
    private int $maxAttempts;

    public function __construct(Transport $transport, Credentials $credentials, Clock $clock, int $maxAttempts = 5)
    {
        $this->transport = $transport;
        $this->signer = new SigV4Signer($credentials);
        $this->clock = $clock;
        $this->maxAttempts = max(1, $maxAttempts);
    }

    /**
     * 把非 2xx 响应解析成异常。各服务的错误格式不同（XML / JSON）。
     */
    abstract protected function parseError(Response $response): AwsException;

    /**
     * @throws AwsException 接口返回错误（重试用尽或不可重试）
     * @throws HttpException 网络错误（重试用尽）
     */
    protected function send(Request $request, string $service, string $region): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $signed = $this->signer->sign($request, $service, $region, $this->clock->now());
                $response = $this->transport->send($signed);
                if ($response->isSuccessful()) {
                    return $response;
                }
                $error = $this->parseError($response);
                $retryable = $error->isRetryable();
            } catch (HttpException $e) {
                $error = $e;
                $retryable = true;
            }

            if (!$retryable || $attempt >= $this->maxAttempts) {
                throw $error;
            }
            $this->clock->sleep($this->backoff($attempt));
        }
    }

    /**
     * 带抖动的指数退避，返回微秒。
     */
    private function backoff(int $attempt): int
    {
        $ceiling = (int) min(self::BACKOFF_CAP_US, self::BACKOFF_BASE_US * (2 ** ($attempt - 1)));
        return random_int(intdiv($ceiling, 2), $ceiling);
    }

    /**
     * 解析 AWS 常见的 XML 错误格式：
     * <ErrorResponse><Error><Code/><Message/></Error><RequestId/></ErrorResponse>（Route 53 等）
     * <Response><Errors><Error><Code/><Message/></Error></Errors><RequestID/></Response>（EC2）
     */
    protected static function parseXmlError(Response $response): AwsException
    {
        $xml = self::loadXml($response->body);
        if ($xml === null) {
            return new AwsException('HttpError', self::bodySnippet($response), $response->status);
        }
        $error = $xml->Error ?? $xml->Errors->Error ?? null;
        $requestId = (string) ($xml->RequestId ?? $xml->RequestID ?? '');
        if ($error === null || !isset($error->Code)) {
            return new AwsException($xml->getName(), self::bodySnippet($response), $response->status, $requestId ?: null);
        }
        return new AwsException((string) $error->Code, (string) $error->Message, $response->status, $requestId ?: null);
    }

    protected static function loadXml(string $body): ?\SimpleXMLElement
    {
        if (trim($body) === '') {
            return null;
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
            return $xml === false ? null : $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    protected static function requireXml(Response $response): \SimpleXMLElement
    {
        $xml = self::loadXml($response->body);
        if ($xml === null) {
            throw new AwsException('InvalidResponse', 'Response is not valid XML: ' . self::bodySnippet($response), $response->status);
        }
        return $xml;
    }

    private static function bodySnippet(Response $response): string
    {
        $body = trim($response->body);
        return $body === '' ? "HTTP {$response->status}" : substr($body, 0, 500);
    }
}
