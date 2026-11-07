<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * 内存版 Route 53，作为 FakeTransport 的处理器使用。
 *
 * 变更批次按顺序逐条校验，并且每一步之后都检查（比真实 Route 53 可能更严格）：
 * - CREATE 的记录不能已存在，DELETE 的记录必须存在且内容完全一致；
 * - Alias 记录指向的同类型记录必须存在；
 * - 同名同类型的记录不能混用路由策略。
 * 任何一步失败，整批不生效（与 Route 53 的原子性一致）。
 */
final class FakeRoute53
{
    private const NS = 'https://route53.amazonaws.com/doc/2013-04-01/';

    public string $zoneId;
    public string $zoneName;
    /** @var array<string,RecordSet> */
    public array $records = [];
    /** @var string[][] 每个成功批次的变更描述 */
    public array $batches = [];
    public int $listCalls = 0;
    /** 列表接口每页最多返回的条数（测试翻页） */
    public int $maxPageSize = 300;
    /** true 时所有请求返回 403（模拟凭证失效） */
    public bool $denied = false;

    public function __construct(string $zoneId = 'Z1', string $zoneName = 'example.com')
    {
        $this->zoneId = $zoneId;
        $this->zoneName = $zoneName;
    }

    public function add(RecordSet $record): void
    {
        $this->records[$record->key()] = $record;
    }

    /**
     * @return RecordSet[]
     */
    public function recordsNamed(string $name): array
    {
        return array_values(array_filter($this->records, static function (RecordSet $r) use ($name): bool {
            return $r->name === $name;
        }));
    }

    public function __invoke(Request $request): Response
    {
        if ($this->denied) {
            return new Response(403, [], '<ErrorResponse><Error><Code>AccessDenied</Code><Message>denied</Message></Error></ErrorResponse>');
        }
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        parse_str((string) parse_url($request->url, PHP_URL_QUERY), $query);

        if ($request->method === 'GET' && preg_match('#/hostedzone/[^/]+/rrset$#', $path)) {
            return $this->list($query);
        }
        if ($request->method === 'POST' && preg_match('#/hostedzone/[^/]+/rrset$#', $path)) {
            return $this->change($request->body);
        }
        if ($request->method === 'GET' && preg_match('#/hostedzone/([^/]+)$#', $path, $m)) {
            return new Response(200, [], '<GetHostedZoneResponse xmlns="' . self::NS . '"><HostedZone>'
                . "<Id>/hostedzone/{$m[1]}</Id><Name>{$this->zoneName}.</Name></HostedZone></GetHostedZoneResponse>");
        }
        return new Response(404, [], '<ErrorResponse><Error><Code>NotFound</Code><Message>' . $path . '</Message></Error></ErrorResponse>');
    }

    /**
     * 与 Route 53 一样：按"标签反转后的名称、类型、SetIdentifier"排序，从 name（及可选的 type / identifier）开始往后列，
     * 超过 maxitems（以及 maxPageSize）时翻页。
     */
    private function list(array $query): Response
    {
        $this->listCalls++;
        $start = self::nameKey(RecordSet::normalizeName((string) ($query['name'] ?? '')));
        if (isset($query['type'])) {
            $start .= "\0" . $query['type'] . "\0" . ($query['identifier'] ?? '');
        }
        $records = array_values(array_filter($this->records, static function (RecordSet $r) use ($start): bool {
            return strcmp(self::sortKey($r), $start) >= 0;
        }));
        usort($records, static function (RecordSet $a, RecordSet $b): int {
            return strcmp(self::sortKey($a), self::sortKey($b));
        });
        $max = min((int) ($query['maxitems'] ?? 300), $this->maxPageSize);
        $page = array_slice($records, 0, $max);
        $next = $records[$max] ?? null;
        $xml = '';
        foreach ($page as $record) {
            // 与真实 Route 53 一样，返回的名称中 * 写成 \052
            $xml .= str_replace('<Name>*.', '<Name>\\052.', $record->toXml());
        }
        $tail = $next === null ? '<IsTruncated>false</IsTruncated>' : '<IsTruncated>true</IsTruncated>'
            . '<NextRecordName>' . RecordSet::escapeName($next->name) . '.</NextRecordName>'
            . "<NextRecordType>{$next->type}</NextRecordType>"
            . ($next->setIdentifier !== null ? '<NextRecordIdentifier>' . htmlspecialchars($next->setIdentifier, ENT_XML1) . '</NextRecordIdentifier>' : '');
        return new Response(200, [], '<ListResourceRecordSetsResponse xmlns="' . self::NS . '"><ResourceRecordSets>'
            . $xml . "</ResourceRecordSets>{$tail}<MaxItems>{$max}</MaxItems></ListResourceRecordSetsResponse>");
    }

    /**
     * www.example.com → com.example.www.
     */
    private static function nameKey(string $name): string
    {
        return implode('.', array_reverse(explode('.', $name))) . '.';
    }

    private static function sortKey(RecordSet $record): string
    {
        return self::nameKey($record->name) . "\0" . $record->type . "\0" . ($record->setIdentifier ?? '');
    }

    private function change(string $body): Response
    {
        $xml = simplexml_load_string($body);
        $working = $this->records;
        $described = [];
        foreach ($xml->ChangeBatch->Changes->Change as $change) {
            $action = (string) $change->Action;
            $record = RecordSet::fromXml($change->ResourceRecordSet);
            $key = $record->key();
            $described[] = "{$action} {$record->type} {$record->name} " . ($record->setIdentifier ?? '');

            if ($action === 'CREATE' && isset($working[$key])) {
                return $this->invalid("Tried to create resource record set {$key} but it already exists");
            }
            if ($action === 'DELETE') {
                if (!isset($working[$key]) || !$working[$key]->sameAs($record)) {
                    return $this->invalid("Tried to delete resource record set {$key} but it was not found");
                }
                unset($working[$key]);
            } else {
                $working[$key] = $record;
            }
            $error = self::validate($working);
            if ($error !== null) {
                return $this->invalid($error);
            }
        }
        $this->records = $working;
        $this->batches[] = $described;
        $id = 'C' . count($this->batches);
        return new Response(200, [], '<ChangeResourceRecordSetsResponse xmlns="' . self::NS . '">'
            . "<ChangeInfo><Id>/change/{$id}</Id><Status>PENDING</Status></ChangeInfo></ChangeResourceRecordSetsResponse>");
    }

    /**
     * @param array<string,RecordSet> $records
     */
    private static function validate(array $records): ?string
    {
        $policies = [];
        foreach ($records as $record) {
            $group = $record->name . '|' . $record->type;
            $policy = $record->multiValueAnswer ? 'multivalue' : ($record->weight !== null ? 'weighted' : ($record->region !== null ? 'latency' : 'simple'));
            if (isset($policies[$group]) && $policies[$group] !== $policy) {
                return "RRSet {$group} mixes routing policies {$policies[$group]} and {$policy}";
            }
            $policies[$group] = $policy;

            if ($record->aliasTarget !== null) {
                $found = false;
                foreach ($records as $other) {
                    if ($other->name === $record->aliasTarget->dnsName && $other->type === $record->type) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    return "Alias {$record->key()} targets {$record->aliasTarget->dnsName} which does not exist";
                }
            }
        }
        return null;
    }

    private function invalid(string $message): Response
    {
        return new Response(400, [], '<InvalidChangeBatch xmlns="' . self::NS . '"><Messages><Message>'
            . htmlspecialchars($message, ENT_XML1) . '</Message></Messages></InvalidChangeBatch>');
    }
}
