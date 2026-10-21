<?php

namespace Wcore\Dnslb\Tests\Support;

use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Http\Request;
use Wcore\Dnslb\Http\Response;

/**
 * 内存版 Cloudflare API（区域和 DNS 记录），作为 FakeTransport 的处理器使用。
 *
 * 批量接口按 Cloudflare 的顺序执行（删除 → 修改 → 新增），任何一项失败则整批不生效，并模拟这些限制：
 * - 不能创建完全相同（类型、名称、内容）的记录；
 * - 同一名称下 CNAME 不能与 A / AAAA 共存；
 * - 代理记录的 TTL 必须为 1（自动），其他记录 30 ~ 86400。
 */
final class FakeCloudflare
{
    public const ZONE_ID = '0123456789abcdef0123456789abcdef';
    public const TOKEN = 'CF-TOKEN';

    public string $zoneName = 'example.com';
    public string $zoneStatus = 'active';
    /** @var array<string,DnsRecord> id => 记录 */
    public array $records = [];
    /** @var string[][] 每个成功批次的变更描述，例如 "POST A app.example.com 10.0.0.1" */
    public array $batches = [];
    public int $listCalls = 0;
    /** 列表接口每页最多返回的条数（测试分页） */
    public int $maxPageSize = 1000;
    private int $sequence = 0;

    public function add(DnsRecord $record): DnsRecord
    {
        $record = clone $record;
        $record->id = $record->id ?? sprintf('%032x', ++$this->sequence);
        $this->records[$record->id] = $record;
        return $record;
    }

    /**
     * @return DnsRecord[]
     */
    public function recordsNamed(string $name): array
    {
        return array_values(array_filter($this->records, static function (DnsRecord $r) use ($name): bool {
            return $r->name === $name;
        }));
    }

    public function __invoke(Request $request): Response
    {
        if ($request->header('Authorization') !== 'Bearer ' . self::TOKEN) {
            return self::error(403, 10000, 'Authentication error');
        }
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        $query = self::query((string) parse_url($request->url, PHP_URL_QUERY));
        $zone = '/client/v4/zones/' . self::ZONE_ID;

        if ($request->method === 'GET' && $path === $zone) {
            return self::ok(['id' => self::ZONE_ID, 'name' => $this->zoneName, 'status' => $this->zoneStatus]);
        }
        if ($request->method === 'GET' && $path === "{$zone}/dns_records") {
            return $this->list($query);
        }
        if ($request->method === 'POST' && $path === "{$zone}/dns_records/batch") {
            return $this->batch((array) json_decode($request->body, true));
        }
        if (strpos($path, '/client/v4/zones/') === 0) {
            return self::error(404, 7003, 'Could not route to ' . $path);
        }
        throw new \LogicException("FakeCloudflare: unexpected {$request->method} {$request->url}");
    }

    private function list(array $query): Response
    {
        $this->listCalls++;
        $name = strtolower($query['name.exact'] ?? '');
        $matching = array_values(array_filter($this->records, static function (DnsRecord $r) use ($name): bool {
            return $name === '' || $r->name === $name;
        }));
        $perPage = min((int) ($query['per_page'] ?? 100), $this->maxPageSize);
        $page = max(1, (int) ($query['page'] ?? 1));
        $items = array_slice($matching, ($page - 1) * $perPage, $perPage);
        return new Response(200, [], (string) json_encode([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => array_map(static function (DnsRecord $r): array {
                return ['id' => $r->id] + $r->toApi() + ['proxiable' => true];
            }, $items),
            'result_info' => [
                'page' => $page,
                'per_page' => $perPage,
                'count' => count($items),
                'total_count' => count($matching),
                'total_pages' => max(1, (int) ceil(count($matching) / $perPage)),
            ],
        ]));
    }

    private function batch(array $body): Response
    {
        $records = $this->records;
        $log = [];
        foreach ($body['deletes'] ?? [] as $item) {
            $id = (string) ($item['id'] ?? '');
            if (!isset($records[$id])) {
                return self::error(400, 81044, "Record {$id} does not exist.");
            }
            $log[] = 'DELETE ' . self::describe($records[$id]);
            unset($records[$id]);
        }
        foreach ($body['patches'] ?? [] as $item) {
            $id = (string) ($item['id'] ?? '');
            if (!isset($records[$id])) {
                return self::error(400, 81044, "Record {$id} does not exist.");
            }
            $patched = DnsRecord::fromApi($item + ['id' => $id] + $records[$id]->toApi());
            unset($records[$id]);
            $error = self::validate($patched, $records);
            if ($error !== null) {
                return $error;
            }
            $records[$id] = $patched;
            $log[] = 'PATCH ' . self::describe($patched);
        }
        foreach ($body['posts'] ?? [] as $item) {
            $record = DnsRecord::fromApi($item);
            $error = self::validate($record, $records);
            if ($error !== null) {
                return $error;
            }
            $record->id = sprintf('%032x', ++$this->sequence);
            $records[$record->id] = $record;
            $log[] = 'POST ' . self::describe($record);
        }
        $this->records = $records;
        $this->batches[] = $log;
        return self::ok(['deletes' => [], 'patches' => [], 'puts' => [], 'posts' => []]);
    }

    /**
     * @param array<string,DnsRecord> $existing
     */
    private static function validate(DnsRecord $record, array $existing): ?Response
    {
        if ($record->type === 'A' && filter_var($record->content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || $record->type === 'AAAA' && filter_var($record->content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return self::error(400, 9005, "Content for {$record->type} record is invalid.");
        }
        if ($record->proxied ? $record->ttl !== DnsRecord::TTL_AUTO : ($record->ttl !== DnsRecord::TTL_AUTO && ($record->ttl < 30 || $record->ttl > 86400))) {
            return self::error(400, 9021, 'Invalid TTL.');
        }
        foreach ($existing as $other) {
            if ($other->name !== $record->name) {
                continue;
            }
            if ($other->type === $record->type && $other->key() === $record->key()) {
                return self::error(400, 81058, 'An identical record already exists.');
            }
            if (($other->type === 'CNAME') !== ($record->type === 'CNAME')) {
                return self::error(400, 81053, 'An A, AAAA, or CNAME record with that host already exists.');
            }
        }
        return null;
    }

    private static function describe(DnsRecord $record): string
    {
        return "{$record->type} {$record->name} {$record->content}";
    }

    /**
     * parse_str 会把参数名中的点号换成下划线（name.exact → name_exact），这里自己解析。
     *
     * @return array<string,string>
     */
    private static function query(string $query): array
    {
        $result = [];
        foreach (array_filter(explode('&', $query)) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $result[urldecode($key)] = urldecode($value);
        }
        return $result;
    }

    private static function ok($result): Response
    {
        return new Response(200, [], (string) json_encode(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]));
    }

    private static function error(int $status, int $code, string $message): Response
    {
        return new Response($status, [], (string) json_encode([
            'success' => false,
            'errors' => [['code' => $code, 'message' => $message]],
            'messages' => [],
            'result' => null,
        ]));
    }
}
