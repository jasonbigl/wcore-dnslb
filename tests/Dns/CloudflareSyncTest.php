<?php

namespace Wcore\Dnslb\Tests\Dns;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Cloudflare\CloudflareException;
use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Dns\DnsChange;
use Wcore\Dnslb\Http\Response;
use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeCloudflare;
use Wcore\Dnslb\Tests\Support\TestApp;

final class CloudflareSyncTest extends TestCase
{
    private const NAME = 'app.example.com';

    public function testPublishesHealthyNodesOfAllPoolsUnderOneNameAndIsIdempotent(): void
    {
        $t = new TestApp([], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $t->addNode('web-frankfurt-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.1', '2001:DB8:0::2');
        $t->addNode('web-tokyo-c', 'tokyo', Node::IN_SERVICE, '10.0.0.3', null, false);
        $t->addNode('web-tokyo-w', 'tokyo', Node::WARMING, '10.0.0.4');
        // 区域内其他名称的记录不受影响
        $t->network->cloudflare->add(new DnsRecord('A', 'www.example.com', '9.9.9.9', true));

        $result = $t->app->dnsSync()->sync();

        self::assertSame([], $result->changeIds);
        self::assertSame([
            'CREATE A app.example.com 10.0.0.1 proxied [dnslb node web-tokyo-a]',
            'CREATE A app.example.com 10.0.1.1 proxied [dnslb node web-frankfurt-b]',
            'CREATE AAAA app.example.com 2001:db8::1 proxied [dnslb node web-tokyo-a]',
            'CREATE AAAA app.example.com 2001:DB8:0::2 proxied [dnslb node web-frankfurt-b]',
        ], self::describe($result->changes));
        self::assertCount(1, $t->network->cloudflare->batches);
        self::assertSame(['web-frankfurt-b' => true, 'web-tokyo-a' => true], self::sorted($t->app->dnsSync()->publishedNodeIds()));
        self::assertCount(1, $t->network->cloudflare->recordsNamed('www.example.com'));

        self::assertFalse($t->app->dnsSync()->sync()->hasChanges(), 'IPv6 is compared in canonical form');
        self::assertCount(1, $t->network->cloudflare->batches);
    }

    public function testAddsNewRecordsBeforeRemovingOldOnes(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $a = $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();

        $a->transition(Node::DRAINING, $t->clock->now());
        $t->app->nodes()->save($a, $t->clock->now());
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $start = $t->clock->now();
        $result = $t->app->dnsSync()->sync();

        self::assertSame([
            'CREATE A app.example.com 10.0.0.2 proxied [dnslb node web-tokyo-b]',
            'DELETE A app.example.com 10.0.0.1 proxied [dnslb node web-tokyo-a]',
        ], self::describe($result->changes));
        self::assertSame([['POST A app.example.com 10.0.0.2'], ['DELETE A app.example.com 10.0.0.1']], array_slice($t->network->cloudflare->batches, 1));
        self::assertSame(5, $t->clock->now() - $start, 'waits for propagation between the two batches');
        self::assertSame(['web-tokyo-b' => true], $t->app->dnsSync()->publishedNodeIds());
    }

    public function testSwitchingProxyModeUpdatesRecordsInPlace(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();

        $dnsOnly = new TestApp(['dns' => ['ipv6' => false, 'proxied' => false, 'ttl' => 120]], true);
        $dnsOnly->network->cloudflare = $t->network->cloudflare;
        $dnsOnly->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $result = $dnsOnly->app->dnsSync()->sync();

        self::assertSame(['UPDATE A app.example.com 10.0.0.1 ttl=120 [dnslb node web-tokyo-a]'], self::describe($result->changes));
        self::assertSame(['PATCH A app.example.com 10.0.0.1'], $t->network->cloudflare->batches[1]);
        $record = $t->network->cloudflare->recordsNamed(self::NAME)[0];
        self::assertFalse($record->proxied);
        self::assertSame(120, $record->ttl);
    }

    public function testExistingRecordsBlockPublishingUntilTakeover(): void
    {
        $t = new TestApp([], true);
        $cloudflare = $t->network->cloudflare;
        $cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.9', true));
        $cloudflare->add(new DnsRecord('AAAA', self::NAME, '2001:db8:ffff::9', true));
        $cloudflare->add(new DnsRecord('TXT', self::NAME, 'keep me', false, 300));
        // 其他服务的记录也算作外来记录
        $cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.10', true, 1, 'dnslb node api-tokyo-x'));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $blocked = $t->app->dnsSync()->sync();
        self::assertTrue($blocked->takeoverPending);
        self::assertFalse($blocked->hasChanges());
        self::assertSame([], $cloudflare->batches);

        $preview = $t->app->dnsSync()->takeover(true);
        self::assertSame([], $cloudflare->batches, 'dry-run only previews');
        $start = $t->clock->now();
        $result = $t->app->dnsSync()->takeover();

        self::assertSame(self::describe($preview->changes), self::describe($result->changes));
        self::assertSame([
            ['POST A app.example.com 10.0.0.1', 'POST AAAA app.example.com 2001:db8::1'],
            ['DELETE A app.example.com 203.0.113.9', 'DELETE AAAA app.example.com 2001:db8:ffff::9', 'DELETE A app.example.com 203.0.113.10'],
        ], $cloudflare->batches, 'new records first, so the name never runs empty');
        self::assertSame(5, $t->clock->now() - $start);
        self::assertSame(['A 10.0.0.1', 'AAAA 2001:db8::1', 'TXT keep me'], self::contents($t));

        $after = $t->app->dnsSync()->sync();
        self::assertFalse($after->takeoverPending);
        self::assertFalse($after->hasChanges());
    }

    public function testTakeoverOfCnameDeletesAndCreatesInOneBatch(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->network->cloudflare->add(new DnsRecord('CNAME', self::NAME, 'old.example.net', true));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $result = $t->app->dnsSync()->takeover();

        self::assertSame([
            'DELETE CNAME app.example.com old.example.net proxied',
            'CREATE A app.example.com 10.0.0.1 proxied [dnslb node web-tokyo-a]',
        ], self::describe($result->changes));
        self::assertSame([['DELETE CNAME app.example.com old.example.net', 'POST A app.example.com 10.0.0.1']], $t->network->cloudflare->batches);
    }

    public function testTakeoverRefusesWithoutServingNodes(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->network->cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.9', true));
        $t->addNode('web-tokyo-w', 'tokyo', Node::WARMING, '10.0.0.1', null, false);

        $this->expectExceptionMessage('No pool has in-service nodes yet');
        $t->app->dnsSync()->takeover();
    }

    public function testStrayRecordAfterTakeoverDoesNotStopNodeUpdates(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $a = $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $t->app->dnsSync()->sync();
        $t->network->cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.9', true));

        $a->v4->healthy = false;
        $t->app->nodes()->saveHealth($a);
        $result = $t->app->dnsSync()->sync();

        self::assertTrue($result->takeoverPending);
        self::assertSame(['DELETE A app.example.com 10.0.0.1 proxied [dnslb node web-tokyo-a]'], self::describe($result->changes));
        self::assertNotEmpty($t->logger->matching('receive traffic too'));
    }

    public function testRenamingAllPoolsKeepsRecordsUntilNewNodesServe(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();

        $t->reconfigure(['dns' => ['ipv6' => false], 'pools' => [
            'tokyo' => null,
            'frankfurt' => null,
            'dallas' => ['max' => 5, 'launch' => [['provider' => 'aws', 'region' => 'ap-northeast-1', 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc']]],
        ]], true);
        $held = $t->app->dnsSync()->sync();

        self::assertSame([], $held->changes);
        self::assertSame(['A 10.0.0.1'], self::contents($t));
        self::assertSame(['dns_empty_held'], $t->eventTypes());

        $t->addNode('web-dallas-b', 'dallas', Node::IN_SERVICE, '10.0.0.2');
        $t->app->dnsSync()->sync();

        self::assertSame(['A 10.0.0.2'], self::contents($t));
        self::assertSame(['dns_empty_held', 'dns_empty_released'], $t->eventTypes());
    }

    public function testFailedPoolIsRemovedWithoutGracePeriod(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $t->addNode('web-frankfurt-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');
        $pool = $t->app->pools()->get('tokyo');
        $pool->setDownSince('v4', $t->clock->now());
        $t->app->pools()->saveHealth($pool);

        $t->app->dnsSync()->sync();

        self::assertSame(['A 10.0.1.1'], self::contents($t), 'pool_failover_after only applies to Route 53 latency routing');
    }

    public function testAllNodesUnhealthyStayPublished(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $t->addNode('web-frankfurt-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.1', null, false);

        $t->app->dnsSync()->sync();

        self::assertSame(['A 10.0.0.1', 'A 10.0.1.1'], self::contents($t));
    }

    public function testDuplicateOwnedRecordIsRemoved(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        // 例如某次重试前请求实际已生效
        $t->network->cloudflare->add(new DnsRecord('A', self::NAME, '10.0.0.1', true, 1, 'dnslb node web-tokyo-old'));

        $result = $t->app->dnsSync()->sync();

        self::assertCount(1, $result->changes);
        self::assertSame(['A 10.0.0.1'], self::contents($t));
        self::assertSame(['web-tokyo-a' => true], $t->app->dnsSync()->publishedNodeIds());
    }

    public function testDryRunDoesNotWrite(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $result = $t->app->dnsSync()->sync(true);

        self::assertTrue($result->dryRun);
        self::assertCount(1, $result->changes);
        self::assertSame([], $t->network->cloudflare->batches);
    }

    public function testFingerprintChangesWithDesiredRecordsOnly(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $a = $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $sync = $t->app->dnsSync();
        $before = $sync->desiredFingerprint($t->clock->now());

        $t->clock->advance(60);
        self::assertSame($before, $sync->desiredFingerprint($t->clock->now()));

        $a->v4->healthy = false;
        $t->app->nodes()->saveHealth($a);
        self::assertNotSame($before, $sync->desiredFingerprint($t->clock->now()));
    }

    public function testListsAllPagesAndRetriesRateLimits(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->network->cloudflare->maxPageSize = 2;
        foreach (['a', 'b', 'c'] as $i => $suffix) {
            $t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}");
        }
        $t->app->dnsSync()->sync();
        $t->transport->queue(new Response(429, ['Retry-After' => '2'], '{"success":false,"errors":[{"code":10000,"message":"Rate limited"}]}'));
        $start = $t->clock->now();

        self::assertCount(3, $t->app->dnsSync()->publishedNodeIds());
        self::assertSame(2, $t->clock->now() - $start, 'honours Retry-After');
    }

    public function testApiErrorsCarryCloudflareCodes(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false], 'cloudflare' => ['api_token' => 'WRONG']], true);

        try {
            $t->app->dnsSync()->publishedNodeIds();
            self::fail('Expected CloudflareException');
        } catch (CloudflareException $e) {
            self::assertSame(403, $e->getHttpStatus());
            self::assertSame([10000], $e->getErrorCodes());
            self::assertStringContainsString('(GET /zones/' . FakeCloudflare::ZONE_ID . '/dns_records, HTTP 403): 10000 Authentication error', $e->getMessage());
        }
    }

    public function testCheck(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]], true);
        $t->network->cloudflare->add(new DnsRecord('CNAME', self::NAME, 'old.example.net', true));
        $t->network->cloudflare->zoneStatus = 'pending';

        $items = $t->app->dnsSync()->check();

        self::assertSame([
            [CheckItem::WARN, 'Cloudflare 区域', 'example.com 的状态为 pending：域名的 NS 还没有切换到 Cloudflare，记录暂不生效'],
            [CheckItem::OK, 'Cloudflare 现有记录', '0 条由 dnslb 管理（代理）'],
            [CheckItem::WARN, '待接管的记录', 'app.example.com 上有 1 条不由 dnslb 管理的记录（CNAME old.example.net（代理）），节点上线后用 Dnslb::takeover() 无中断切换'],
        ], array_map(static function (CheckItem $item): array {
            return [$item->status, $item->name, $item->message];
        }, $items));
    }

    /**
     * @param DnsChange[] $changes
     * @return string[]
     */
    private static function describe(array $changes): array
    {
        return array_map(static function (DnsChange $change): string {
            return $change->describe();
        }, $changes);
    }

    /**
     * @return string[] dns.name 下的记录，例如 "A 10.0.0.1"
     */
    private static function contents(TestApp $t): array
    {
        $result = array_map(static function (DnsRecord $r): string {
            return "{$r->type} {$r->content}";
        }, $t->network->cloudflare->recordsNamed(self::NAME));
        sort($result);
        return $result;
    }

    private static function sorted(array $map): array
    {
        ksort($map);
        return $map;
    }
}
