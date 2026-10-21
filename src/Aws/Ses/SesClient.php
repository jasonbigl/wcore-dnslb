<?php

namespace Wcore\Dnslb\Aws\Ses;

use Wcore\Dnslb\Aws\AwsClient;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * SES v2 REST-JSON 接口。
 */
final class SesClient extends AwsClient
{
    private const SERVICE = 'ses';

    /**
     * @param string[] $to
     * @return string MessageId
     */
    public function sendEmail(string $region, string $from, array $to, string $subject, string $textBody, ?string $htmlBody = null): string
    {
        $body = ['Text' => ['Data' => $textBody, 'Charset' => 'UTF-8']];
        if ($htmlBody !== null) {
            $body['Html'] = ['Data' => $htmlBody, 'Charset' => 'UTF-8'];
        }
        $payload = [
            'FromEmailAddress' => $from,
            'Destination' => ['ToAddresses' => array_values($to)],
            'Content' => [
                'Simple' => [
                    'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                    'Body' => $body,
                ],
            ],
        ];
        $data = $this->request($region, 'POST', '/v2/email/outbound-emails', $payload)->json();
        return (string) ($data['MessageId'] ?? '');
    }

    /**
     * 账号状态，例如 SendingEnabled、ProductionAccessEnabled（是否已移出沙箱）。
     */
    public function getAccount(string $region): array
    {
        return (array) $this->request($region, 'GET', '/v2/email/account')->json();
    }

    protected function parseError(Response $response): AwsException
    {
        $data = json_decode($response->body, true);
        $code = (string) $response->header('x-amzn-errortype');
        if ($code === '' && is_array($data)) {
            $code = (string) ($data['__type'] ?? $data['code'] ?? '');
        }
        // 例如 "MessageRejected:http://internal.amazon.com/coral/com.amazonaws.sesv2/"
        $code = explode(':', $code, 2)[0];
        $code = substr((string) strrchr('#' . $code, '#'), 1);
        $message = is_array($data) ? (string) ($data['message'] ?? $data['Message'] ?? '') : trim($response->body);

        return new AwsException(
            $code !== '' ? $code : 'HttpError',
            $message !== '' ? $message : "HTTP {$response->status}",
            $response->status,
            $response->header('x-amzn-requestid')
        );
    }

    private function request(string $region, string $method, string $path, ?array $payload = null): Response
    {
        $body = $payload === null ? '' : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = $payload === null ? [] : ['Content-Type' => 'application/json'];
        $request = new Request($method, "https://email.{$region}.amazonaws.com{$path}", $headers, $body);
        return $this->send($request, self::SERVICE, $region);
    }
}
