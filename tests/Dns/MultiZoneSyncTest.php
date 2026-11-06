<?php

namespace Wcore\Dnslb\Tests\Dns;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Dns\DnsChange;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeCloudflare;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\FakeRoute53;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * 同一批节点发布到多个区域（dns.zones）。
 */
final class MultiZoneSyncTest extends TestCase
{
    private const ROUTE53 = ['dns' => ['ipv6' => false, 'zones' => [
        ['zone_id' => 'Z1', 'name' => 'app.example.com'],
        ['zone_id' => '/hostedzone/Z2', 'name' => 'app.example.net'],
    ]]];
    private const CF_ZONE2 = 'fedcba9876543210fedcba9876543210';

    public function testPublishesTheSameNodesToEveryRoute53Zone(): void
    {
        [$t, $net] = self::route53();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-tokyo-c', 'tokyo', Node::IN_SERVICE, '10.0.0.3');
        $t->addNode('web-frankfurt-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');

        $result = $t->app->dnsSync()->sync();

        self::assertCount(10, $result->changes);
        self::assertSame(['C1', 'C1'], $result->changeIds, 'one batch per hosted zone');
        foreach ([[$t->network->route53, 'com'], [$net, 'net']] as [$zone, $tld]) {
            self::assertSame(['A web-tokyo-a 10.0.0.1', 'A web-tokyo-c 10.0.0.3'], $t->records("tokyo.pool.app.example.{$tld}", $zone));
            self::assertSame(['A web-frankfurt-b 10.0.1.1'], $t->records("frankfurt.pool.app.example.{$tld}", $zone));
            self::assertSame([
                "A dnslb-frankfurt -> frankfurt.pool.app.example.{$tld}",
                "A dnslb-tokyo -> tokyo.pool.app.example.{$tld}",
            ], $t->records("app.example.{$tld}", $zone));
        }
        foreach ($net->recordsNamed('app.example.net') as $record) {
            self::assertSame('Z2', $record->aliasTarget->hostedZoneId, 'aliases target pool records in their own hosted zone');
        }
        self::assertSame([], $t->records('tokyo.pool.app.example.net'), 'nothing is written into the other zone');

        self::assertFalse($t->app->dnsSync()->sync()->hasChanges());
        self::assertSame(['web-frankfurt-b' => true, 'web-tokyo-a' => true, 'web-tokyo-c' => true], $t->app->dnsSync()->publishedNodeIds());

        $a = $t->node('web-tokyo-a');
        $a->v4->healthy = false;
        $t->app->nodes()->saveHealth($a);
        self::assertSame([
            'DELETE A tokyo.pool.app.example.com [web-tokyo-a] 10.0.0.1',
            'DELETE A tokyo.pool.app.example.net [web-tokyo-a] 10.0.0.1',
        ], self::describe($t->app->dnsSync()->sync()->changes));
    }

    public function testOneFailingZoneDoesNotBlockTheOthers(): void
    {
        [$t, $net] = self::route53();
        $net->denied = true;
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        try {
            $t->app->dnsSync()->sync();
            self::fail('Expected the failing zone to be reported');
        } catch (\RuntimeException $e) {
            self::assertStringStartsWith('app.example.net: ', $e->getMessage());
            self::assertNotNull($e->getPrevious());
        }
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $t->records('tokyo.pool.app.example.com'));
        self::assertSame(['A dnslb-tokyo -> tokyo.pool.app.example.com'], $t->records('app.example.com'));
        self::assertNotEmpty($t->logger->matching('DNS sync of app.example.net failed'));

        // 读不到某个区域时无法确认节点已经摘除
        try {
            $t->app->dnsSync()->publishedNodeIds();
            self::fail('Expected publishedNodeIds() to fail while a zone is unreadable');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('denied', $e->getMessage());
        }

        $net->denied = false;
        $retry = $t->app->dnsSync()->sync();
        self::assertSame([
            'UPSERT A tokyo.pool.app.example.net [web-tokyo-a] 10.0.0.1',
            'UPSERT A app.example.net [dnslb-tokyo] region=ap-northeast-1 -> tokyo.pool.app.example.net',
        ], self::describe($retry->changes), 'the zone that was already in sync is left alone');
    }

    public function testNodeCountsAsPublishedUntilRemovedFromEveryZone(): void
    {
        [$t, $net] = self::route53();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-tokyo-c', 'tokyo', Node::IN_SERVICE, '10.0.0.3');
        $t->app->dnsSync()->sync();

        $a = $t->node('web-tokyo-a');
        $a->transition(Node::DRAINING, $t->clock->now());
        $t->app->nodes()->save($a, $t->clock->now());
        $net->denied = true;
        try {
            $t->app->dnsSync()->sync();
            self::fail('Expected the failing zone to be reported');
        } catch (\RuntimeException $e) {
        }
        self::assertSame(['A web-tokyo-c 10.0.0.3'], $t->records('tokyo.pool.app.example.com'));

        $net->denied = false;
        self::assertArrayHasKey('web-tokyo-a', $t->app->dnsSync()->publishedNodeIds(), 'still published in app.example.net');
        $t->app->dnsSync()->sync();
        self::assertSame(['web-tokyo-c' => true], $t->app->dnsSync()->publishedNodeIds());
    }

    public function testTakeoverIsTrackedPerZone(): void
    {
        [$t, $net] = self::route53(['pools' => ['tokyo' => ['min' => 1], 'frankfurt' => ['min' => 0]]]);
        $foreign = new RecordSet('app.example.net', 'A');
        $foreign->ttl = 300;
        $foreign->values = ['203.0.113.9'];
        $net->add($foreign);
        $aws = new FakeProvider('aws');
        $lb = $t->lb(['aws' => $aws]);

        $lb->tick();
        $node = $t->app->nodes()->live()[0];
        $aws->boot($node->nodeId, '10.0.0.1');
        $t->clock->advance(60);
        $lb->tick();
        for ($i = 0; $i < 2; $i++) {
            $lb->health(0, true);
            $t->clock->advance(10);
        }
        $lb->tick();

        $message = self::messages($t, 'node_in_service')[0];
        self::assertMatchesRegularExpression(
            '/^节点通过健康检查，加入 DNS（从创建到上线 \d+ 秒）。app\.example\.net 上还有不由 dnslb 管理的记录，接管之前这些名称的流量不会到达节点$/u',
            $message
        );
        self::assertSame(['A dnslb-tokyo -> tokyo.pool.app.example.com'], $t->records('app.example.com'));
        self::assertSame(['A  203.0.113.9'], $t->records('app.example.net', $net), 'the foreign record is left alone until takeover');
        self::assertSame(["A {$node->nodeId} 10.0.0.1"], $t->records('tokyo.pool.app.example.net', $net), 'pool records are published ahead of takeover');
        self::assertSame([
            ['name' => 'app.example.com', 'zone_id' => 'Z1', 'takeover_pending' => false],
            ['name' => 'app.example.net', 'zone_id' => 'Z2', 'takeover_pending' => true],
        ], $lb->status()['dns']['zones']);

        $comBatches = count($t->network->route53->batches);
        $result = $lb->takeover(false, 'App.Example.net.');
        self::assertSame([
            'DELETE A app.example.net 203.0.113.9',
            'UPSERT A app.example.net [dnslb-tokyo] region=ap-northeast-1 -> tokyo.pool.app.example.net',
        ], self::describe($result->changes));
        self::assertCount($comBatches, $t->network->route53->batches, 'the other zone is not touched');
        self::assertSame(['A dnslb-tokyo -> tokyo.pool.app.example.net'], $t->records('app.example.net', $net));
        $takeover = $t->app->events()->recent(1)[0];
        self::assertSame('dns_takeover', $takeover->type);
        self::assertSame(['dns_name' => 'app.example.net'], $takeover->context);
        self::assertSame([false, false], array_column($lb->status()['dns']['zones'], 'takeover_pending'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown DNS name "app.example.org"');
        $lb->takeover(true, 'app.example.org');
    }

    public function testEmptyPublishHoldIsPerZone(): void
    {
        [$t, $net] = self::route53();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();

        // 所有节点同时丢失：两个区域都保留现有记录
        $a = $t->node('web-tokyo-a');
        $a->transition(Node::DRAINING, $t->clock->now());
        $t->app->nodes()->save($a, $t->clock->now());
        self::assertFalse($t->app->dnsSync()->sync()->hasChanges());
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $t->records('tokyo.pool.app.example.net', $net));
        self::assertSame(['app.example.com', 'app.example.net'], self::zonesOf($t, 'dns_empty_held'));

        // 只在一个区域手动删除记录：只有这个区域解除
        $net->records = [];
        $t->app->dnsSync()->sync();
        self::assertSame(['app.example.net'], self::zonesOf($t, 'dns_empty_released'));
        $t->app->dnsSync()->sync();
        self::assertSame(['app.example.com', 'app.example.net'], self::zonesOf($t, 'dns_empty_held'), 'reported once per zone');
        self::assertSame(['web-tokyo-a' => true], $t->app->dnsSync()->publishedNodeIds());
    }

    public function testAddingAZoneLaterOnlyWritesTheNewZone(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        $net = $t->network->addRoute53Zone('Z2', 'example.net');

        $t->reconfigure(self::ROUTE53);
        $result = $t->app->dnsSync()->sync();

        self::assertCount(1, $t->network->route53->batches);
        self::assertSame([
            'UPSERT A tokyo.pool.app.example.net [web-tokyo-a] 10.0.0.1',
            'UPSERT A app.example.net [dnslb-tokyo] region=ap-northeast-1 -> tokyo.pool.app.example.net',
        ], self::describe($result->changes));
        self::assertCount(1, $net->batches);
    }

    public function testCheckNamesEachZone(): void
    {
        [$t] = self::route53();
        $names = array_map(static function ($item): string {
            return $item->name;
        }, $t->app->dnsSync()->check());

        self::assertContains('Route 53 托管区域（app.example.com）', $names);
        self::assertContains('Route 53 托管区域（app.example.net）', $names);
        self::assertContains('Route 53 现有记录（app.example.net）', $names);
    }

    public function testCloudflarePublishesToEveryZone(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false, 'zones' => [
            ['zone_id' => FakeCloudflare::ZONE_ID, 'name' => 'app.example.com'],
            ['zone_id' => self::CF_ZONE2, 'name' => '*.example.net'],
        ]]], true);
        $net = $t->network->addCloudflareZone(self::CF_ZONE2, 'example.net');
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-frankfurt-b', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');

        $t->app->dnsSync()->sync();

        self::assertSame(['A 10.0.0.1', 'A 10.0.1.1'], self::contents($t->network->cloudflare, 'app.example.com'));
        self::assertSame(['A 10.0.0.1', 'A 10.0.1.1'], self::contents($net, '*.example.net'));
        self::assertSame([], $t->network->cloudflare->recordsNamed('*.example.net'));
        self::assertSame(['web-frankfurt-b' => true, 'web-tokyo-a' => true], $t->app->dnsSync()->publishedNodeIds());
        self::assertFalse($t->app->dnsSync()->sync()->hasChanges());

        $b = $t->node('web-frankfurt-b');
        $b->v4->healthy = false;
        $t->app->nodes()->saveHealth($b);
        $t->app->dnsSync()->sync();
        self::assertSame(['A 10.0.0.1'], self::contents($t->network->cloudflare, 'app.example.com'));
        self::assertSame(['A 10.0.0.1'], self::contents($net, '*.example.net'));
    }

    /**
     * @return array{0: TestApp, 1: FakeRoute53}
     */
    private static function route53(array $overrides = []): array
    {
        $t = new TestApp(array_replace_recursive(self::ROUTE53, $overrides));
        return [$t, $t->network->addRoute53Zone('Z2', 'example.net')];
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
     * @return string[]
     */
    private static function contents(FakeCloudflare $zone, string $name): array
    {
        $result = array_map(static function (DnsRecord $r): string {
            return "{$r->type} {$r->content}";
        }, $zone->recordsNamed($name));
        sort($result);
        return $result;
    }

    /**
     * @return string[] 指定类型事件的内容，按时间顺序
     */
    private static function messages(TestApp $t, string $type): array
    {
        $events = array_filter($t->app->events()->recent(1000), static function ($event) use ($type): bool {
            return $event->type === $type;
        });
        return array_reverse(array_values(array_map(static function ($event): string {
            return $event->message;
        }, $events)));
    }

    /**
     * @return string[] 指定类型事件所属的区域，按时间顺序
     */
    private static function zonesOf(TestApp $t, string $type): array
    {
        $events = array_filter($t->app->events()->recent(1000), static function ($event) use ($type): bool {
            return $event->type === $type;
        });
        return array_reverse(array_values(array_map(static function ($event): string {
            return (string) ($event->context['dns_name'] ?? '');
        }, $events)));
    }
}
