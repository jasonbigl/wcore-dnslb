<?php

namespace Wcore\Dnslb\Tests\Dns;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Route53\AliasTarget;
use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Dns\DnsConflictException;
use Wcore\Dnslb\Dns\Route53Sync;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\TestApp;

final class Route53SyncTest extends TestCase
{
    private const POOL = 'tokyo.pool.app.example.com';

    public function testCreatesRecordsInDependencyOrderAndIsIdempotent(): void
    {
        $t = new TestApp();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2', '2001:db8::2');
        // 区域外的无关记录不受影响
        $t->network->route53->add(RecordSet::multiValue('www.example.com', 'A', 'other', 300, ['9.9.9.9']));

        $result = $t->app->dnsSync()->sync();

        self::assertCount(6, $result->changes);
        self::assertSame(['C1'], $result->changeIds);
        self::assertSame(['A web-tokyo-a 10.0.0.1', 'A web-tokyo-b 10.0.0.2', 'AAAA web-tokyo-a 2001:db8::1', 'AAAA web-tokyo-b 2001:db8::2'], $t->records(self::POOL));
        self::assertSame(['A dnslb-tokyo -> ' . self::POOL, 'AAAA dnslb-tokyo -> ' . self::POOL], $t->records('app.example.com'));
        self::assertSame(['A other 9.9.9.9'], $t->records('www.example.com'));

        $again = $t->app->dnsSync()->sync();
        self::assertFalse($again->hasChanges());
        self::assertCount(1, $t->network->route53->batches);
    }

    public function testRemovesUnhealthyNodeAndWholePoolInSafeOrder(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');
        $a = $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $b = $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $t->app->dnsSync()->sync();

        $a->v4->healthy = false;
        $t->app->nodes()->saveHealth($a);
        $result = $t->app->dnsSync()->sync();
        self::assertSame(['DELETE A tokyo.pool.app.example.com [web-tokyo-a] 10.0.0.1'], self::describe($result->changes));

        // 整池故障超过宽限期：latency 记录必须先于池记录删除
        $b->v4->healthy = false;
        $b->v4->since = $t->clock->now();
        $t->app->nodes()->saveHealth($b);
        $pool = $t->app->pools()->get('tokyo');
        $pool->setDownSince('v4', $t->clock->now());
        $t->app->pools()->saveHealth($pool);
        $t->clock->advance(181);

        $result = $t->app->dnsSync()->sync();
        self::assertSame([
            'DELETE A app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
            'DELETE A tokyo.pool.app.example.com [web-tokyo-b] 10.0.0.2',
        ], self::describe($result->changes));
        self::assertSame([], $t->records(self::POOL));
        self::assertSame(['A dnslb-frankfurt -> frankfurt.pool.app.example.com'], $t->records('app.example.com'));
    }

    public function testSwitchingRoutingPolicyDetachesLatencyRecordFirst(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $t->app->dnsSync()->sync();

        $weighted = new TestApp(['dns' => ['ipv6' => false, 'pool_routing' => 'weighted']]);
        $weighted->network->route53->records = $t->network->route53->records;
        $weighted->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $weighted->addNode('web-tokyo-c', 'tokyo', Node::IN_SERVICE, '10.0.0.3');

        $result = $weighted->app->dnsSync()->sync();

        self::assertSame([
            'DELETE A app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
            'DELETE A tokyo.pool.app.example.com [web-tokyo-a] 10.0.0.1',
            'DELETE A tokyo.pool.app.example.com [web-tokyo-b] 10.0.0.2',
            'UPSERT A tokyo.pool.app.example.com [web-tokyo-a] weight=10 10.0.0.1',
            'UPSERT A tokyo.pool.app.example.com [web-tokyo-c] weight=10 10.0.0.3',
            'UPSERT A app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
        ], self::describe($result->changes));
        self::assertSame(['A web-tokyo-a 10.0.0.1', 'A web-tokyo-c 10.0.0.3'], $weighted->records(self::POOL));
    }

    public function testCleansUpPoolsRemovedFromConfig(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $route53 = $t->network->route53;
        $route53->add(RecordSet::multiValue('osaka.pool.app.example.com', 'A', 'web-osaka-a', 60, ['10.0.5.1']));
        $route53->add(RecordSet::latencyAlias('app.example.com', 'A', 'dnslb-osaka', 'ap-northeast-3', new AliasTarget('Z1', 'osaka.pool.app.example.com', true)));
        // 不属于本服务的记录（SetIdentifier 前缀不同）不能删除
        $route53->add(RecordSet::multiValue('osaka.pool.app.example.com', 'A', 'manual-1', 60, ['10.0.5.9']));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $t->app->dnsSync()->sync();

        self::assertSame(['A dnslb-tokyo -> ' . self::POOL], $t->records('app.example.com'));
        self::assertSame(['A manual-1 10.0.5.9'], $t->records('osaka.pool.app.example.com'));
    }

    public function testForeignRecordsOnPublicNameDeferLatencyLayerUntilTakeover(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->network->route53->add(self::simple('app.example.com', 'A', '203.0.113.9'));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $result = $t->app->dnsSync()->sync();

        self::assertTrue($result->takeoverPending);
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $t->records(self::POOL), 'pool records are published ahead of takeover');
        self::assertSame(['A  203.0.113.9'], $t->records('app.example.com'), 'existing record is left alone');
        self::assertNotEmpty($t->logger->matching('Call Dnslb::takeover()'));

        $this->expectException(DnsConflictException::class);
        $t->app->dnsSync()->fetchActual();
    }

    public function testTakeoverReplacesForeignRecordsInOneBatch(): void
    {
        $t = new TestApp();
        $route53 = $t->network->route53;
        $route53->add(self::simple('app.example.com', 'A', '203.0.113.9'));
        $route53->add(self::simple('app.example.com', 'AAAA', '2001:db8:ffff::9'));
        $route53->add(self::simple('app.example.com', 'TXT', '"keep me"'));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $preview = $t->app->dnsSync()->takeover(true);
        self::assertSame([], $route53->batches, 'dry-run only previews');
        $result = $t->app->dnsSync()->takeover(false);

        self::assertSame(self::describe($preview->changes), self::describe($result->changes));
        self::assertSame([
            'UPSERT AAAA tokyo.pool.app.example.com [web-tokyo-a] 2001:db8::1',
            'UPSERT A tokyo.pool.app.example.com [web-tokyo-a] 10.0.0.1',
            'DELETE AAAA app.example.com 2001:db8:ffff::9',
            'DELETE A app.example.com 203.0.113.9',
            'UPSERT AAAA app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
            'UPSERT A app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
        ], self::describe($result->changes));
        self::assertCount(1, $route53->batches, 'takeover must be a single atomic batch');
        self::assertSame([
            'A dnslb-tokyo -> ' . self::POOL,
            'AAAA dnslb-tokyo -> ' . self::POOL,
            'TXT  "keep me"',
        ], $t->records('app.example.com'));
        self::assertFalse($t->app->dnsSync()->sync()->takeoverPending);
    }

    public function testForeignRecordAfterTakeoverKeepsLatencyRecords(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        // 接管后有人把普通 AAAA 记录加了回来
        $t->network->route53->add(self::simple('app.example.com', 'AAAA', '2001:db8:ffff::9'));
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');

        $result = $t->app->dnsSync()->sync();

        self::assertTrue($result->takeoverPending);
        self::assertSame(['UPSERT A tokyo.pool.app.example.com [web-tokyo-b] 10.0.0.2'], self::describe($result->changes));
        self::assertSame(['A dnslb-tokyo -> ' . self::POOL, 'AAAA  2001:db8:ffff::9'], $t->records('app.example.com'));
        self::assertNotEmpty($t->logger->matching('receive traffic too'));
    }

    public function testRenamingAllPoolsKeepsRecordsUntilNewNodesServe(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        $before = $t->records('app.example.com');

        // 一次部署中把全部池换掉：新池还没有节点，期望发布的记录为空
        $t->reconfigure(['dns' => ['ipv6' => false], 'pools' => [
            'tokyo' => null,
            'frankfurt' => null,
            'ap-tokyo' => ['latency_region' => 'ap-northeast-1', 'max' => 5, 'launch' => [['provider' => 'aws', 'region' => 'ap-northeast-1', 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc']]],
        ]]);
        $held = $t->app->dnsSync()->sync();
        $t->app->dnsSync()->sync();

        self::assertSame([], $held->changes);
        self::assertSame($before, $t->records('app.example.com'));
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $t->records(self::POOL));
        self::assertSame(['web-tokyo-a' => true], $t->app->dnsSync()->publishedNodeIds(), 'still in DNS, so its drain timer does not start');
        self::assertSame(['dns_empty_held'], $t->eventTypes(), 'reported once');

        $t->addNode('web-ap-tokyo-b', 'ap-tokyo', Node::IN_SERVICE, '10.0.0.2');
        $t->app->dnsSync()->sync();

        self::assertSame(['A dnslb-ap-tokyo -> ap-tokyo.pool.app.example.com'], $t->records('app.example.com'));
        self::assertSame([], $t->records(self::POOL));
        self::assertSame(['dns_empty_held', 'dns_empty_released'], $t->eventTypes());
    }

    public function testTakeoverRefusesWithoutServingNodes(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->network->route53->add(self::simple('app.example.com', 'A', '203.0.113.9'));
        $t->addNode('web-tokyo-w', 'tokyo', Node::WARMING, '10.0.0.1', null, false);

        $this->expectExceptionMessage('No pool has in-service nodes yet');
        $t->app->dnsSync()->takeover(false);
    }

    public function testRefusesForeignRecordsOnPoolNames(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->network->route53->add(self::simple(self::POOL, 'A', '203.0.113.9'));
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        try {
            $t->app->dnsSync()->sync();
            self::fail('Expected DnsConflictException');
        } catch (DnsConflictException $e) {
            self::assertStringContainsString('pool record names must be dedicated to dnslb', $e->getMessage());
        }
        self::assertSame([], $t->network->route53->batches);
    }

    public function testWildcardDomain(): void
    {
        $t = new TestApp(['dns' => ['name' => '*.example.net'], 'health_check' => ['host' => 'health.example.net']]);
        $t->network->route53->zoneName = 'example.net';
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $t->app->dnsSync()->sync();

        self::assertSame([
            'A dnslb-tokyo -> tokyo.dnslb-pool.example.net',
            'AAAA dnslb-tokyo -> tokyo.dnslb-pool.example.net',
        ], $t->records('*.example.net'));
        self::assertSame(['A web-tokyo-a 10.0.0.1', 'AAAA web-tokyo-a 2001:db8::1'], $t->records('tokyo.dnslb-pool.example.net'));
        self::assertFalse($t->app->dnsSync()->sync()->hasChanges(), 'wildcard records read back with \\052 escapes must match');

        // 探测请求使用具体的健康检查域名
        $request = $t->app->prober()->nodeRequest(new \Wcore\Dnslb\Health\ProbeTarget('web-tokyo-a', 'v4', '10.0.0.1'));
        self::assertSame('https://health.example.net:443/health', $request->url);
    }

    public function testDryRunDoesNotSubmit(): void
    {
        $t = new TestApp();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');

        $result = $t->app->dnsSync()->sync(true);

        self::assertTrue($result->dryRun);
        self::assertCount(4, $result->changes);
        self::assertSame([], $t->network->route53->batches);
        self::assertCount(4, $t->logger->matching('[dry-run] DNS UPSERT'));
    }

    public function testValueChangeIsUpserted(): void
    {
        $desired = ['k' => RecordSet::multiValue('p.example.com', 'A', 'web-x', 60, ['1.1.1.1'])];
        $actual = ['k' => RecordSet::multiValue('p.example.com', 'A', 'web-x', 300, ['1.1.1.1'])];

        self::assertSame(['UPSERT A p.example.com [web-x] 1.1.1.1'], self::describe(Route53Sync::diff($desired, $actual, 'app.example.com')));
        self::assertSame([], Route53Sync::diff($desired, $desired, 'app.example.com'));
    }

    private static function simple(string $name, string $type, string $value): RecordSet
    {
        $record = new RecordSet($name, $type);
        $record->ttl = 300;
        $record->values = [$value];
        return $record;
    }

    /**
     * @param Change[] $changes
     * @return string[]
     */
    private static function describe(array $changes): array
    {
        return array_map(static function (Change $change): string {
            return $change->describe();
        }, $changes);
    }
}
