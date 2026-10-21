<?php

namespace Wcore\Dnslb\Aws\Route53;

use Wcore\Dnslb\Aws\AwsClient;
use Wcore\Dnslb\Aws\AwsException;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * Route 53 REST-XML 接口（API 版本 2013-04-01）。
 */
final class Route53Client extends AwsClient
{
    private const ENDPOINT = 'https://route53.amazonaws.com/2013-04-01';
    private const NAMESPACE = 'https://route53.amazonaws.com/doc/2013-04-01/';
    private const SERVICE = 'route53';
    private const REGION = 'us-east-1';
    private const MAX_ITEMS = 300;

    /**
     * @return array{id:string,name:string}
     */
    public function getHostedZone(string $zoneId): array
    {
        $zoneId = self::stripPrefix($zoneId);
        $xml = self::requireXml($this->request('GET', '/hostedzone/' . rawurlencode($zoneId)));
        return [
            'id' => self::stripPrefix((string) $xml->HostedZone->Id),
            'name' => RecordSet::normalizeName((string) $xml->HostedZone->Name),
        ];
    }

    /**
     * 列出指定名称（精确匹配）下的全部记录，自动翻页。
     *
     * @return RecordSet[]
     */
    public function listRecordSetsByName(string $zoneId, string $name): array
    {
        $zoneId = self::stripPrefix($zoneId);
        $name = RecordSet::normalizeName($name);
        $query = ['name' => RecordSet::escapeName($name) . '.', 'maxitems' => (string) self::MAX_ITEMS];
        $records = [];

        while (true) {
            $path = '/hostedzone/' . rawurlencode($zoneId) . '/rrset?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $xml = self::requireXml($this->request('GET', $path));
            foreach ($xml->ResourceRecordSets->ResourceRecordSet as $item) {
                $record = RecordSet::fromXml($item);
                if ($record->name !== $name) {
                    // 结果按名称排序，出现其他名称说明目标名称已经列完
                    return $records;
                }
                $records[] = $record;
            }
            if ((string) $xml->IsTruncated !== 'true'
                || RecordSet::normalizeName((string) $xml->NextRecordName) !== $name) {
                return $records;
            }
            $query = [
                'name' => (string) $xml->NextRecordName,
                'type' => (string) $xml->NextRecordType,
                'maxitems' => (string) self::MAX_ITEMS,
            ];
            if (isset($xml->NextRecordIdentifier)) {
                $query['identifier'] = (string) $xml->NextRecordIdentifier;
            }
        }
    }

    /**
     * 原子地提交一批变更：要么全部成功，要么全部不生效。
     *
     * @param Change[] $changes
     * @return string 变更 ID，可用 getChange() 查询是否已同步到所有权威服务器
     */
    public function changeRecordSets(string $zoneId, array $changes, string $comment = ''): string
    {
        if ($changes === []) {
            throw new \InvalidArgumentException('Change batch must not be empty');
        }
        $zoneId = self::stripPrefix($zoneId);
        $body = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<ChangeResourceRecordSetsRequest xmlns="' . self::NAMESPACE . '"><ChangeBatch>';
        if ($comment !== '') {
            $body .= '<Comment>' . htmlspecialchars($comment, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</Comment>';
        }
        $body .= '<Changes>';
        foreach ($changes as $change) {
            $body .= $change->toXml();
        }
        $body .= '</Changes></ChangeBatch></ChangeResourceRecordSetsRequest>';

        $xml = self::requireXml($this->request('POST', '/hostedzone/' . rawurlencode($zoneId) . '/rrset', $body));
        return self::stripPrefix((string) $xml->ChangeInfo->Id);
    }

    /**
     * @return string PENDING 或 INSYNC
     */
    public function getChange(string $changeId): string
    {
        $xml = self::requireXml($this->request('GET', '/change/' . rawurlencode(self::stripPrefix($changeId))));
        return (string) $xml->ChangeInfo->Status;
    }

    /**
     * 让 Route 53 模拟一次 DNS 查询，可指定客户端子网（EDNS Client Subnet）来模拟不同地区的用户。
     *
     * @return array{code:string,values:string[]}
     */
    public function testDnsAnswer(string $zoneId, string $name, string $type, ?string $clientSubnetIp = null, ?int $clientSubnetMask = null): array
    {
        $query = [
            'hostedzoneid' => self::stripPrefix($zoneId),
            'recordname' => RecordSet::normalizeName($name),
            'recordtype' => strtoupper($type),
        ];
        if ($clientSubnetIp !== null) {
            $query['edns0clientsubnetip'] = $clientSubnetIp;
            if ($clientSubnetMask !== null) {
                $query['edns0clientsubnetmask'] = (string) $clientSubnetMask;
            }
        }
        $xml = self::requireXml($this->request('GET', '/testdnsanswer?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)));
        $values = [];
        foreach ($xml->RecordData->RecordDataEntry ?? [] as $entry) {
            $values[] = (string) $entry;
        }
        return ['code' => (string) $xml->ResponseCode, 'values' => $values];
    }

    protected function parseError(Response $response): AwsException
    {
        $xml = self::loadXml($response->body);
        // 变更批次校验失败时返回的是 <InvalidChangeBatch><Messages><Message/>...</Messages></InvalidChangeBatch>
        if ($xml !== null && $xml->getName() === 'InvalidChangeBatch') {
            $messages = [];
            foreach ($xml->Messages->Message ?? [] as $message) {
                $messages[] = (string) $message;
            }
            if ($messages === [] && isset($xml->Message)) {
                $messages[] = (string) $xml->Message;
            }
            $requestId = (string) ($xml->RequestId ?? '');
            return new AwsException('InvalidChangeBatch', implode('; ', $messages), $response->status, $requestId ?: null);
        }
        return self::parseXmlError($response);
    }

    private function request(string $method, string $path, string $body = ''): Response
    {
        $headers = $body === '' ? [] : ['Content-Type' => 'application/xml'];
        return $this->send(new Request($method, self::ENDPOINT . $path, $headers, $body), self::SERVICE, self::REGION);
    }

    /**
     * 去掉 "/hostedzone/"、"/change/" 之类的前缀。
     */
    private static function stripPrefix(string $id): string
    {
        $pos = strrpos($id, '/');
        return $pos === false ? $id : substr($id, $pos + 1);
    }
}
