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
        self::assertNotContains('pool_withdrawn', $t->eventTypes());

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
        $events = $t->app->events()->recent(10);
        self::assertSame('pool_withdrawn', $events[0]->type);
        self::assertSame('tokyo', $events[0]->pool);
        self::assertSame('池 IPv4 整池故障 181 秒，已把该区域（ap-northeast-1）从 app.example.com 撤下，流量转到其他区域；恢复后自动重新发布', $events[0]->message);

        // 撤下只记录一次
        $t->clock->advance(60);
        self::assertFalse($t->app->dnsSync()->sync()->hasChanges());
        self::assertCount(1, array_keys($t->eventTypes(), 'pool_withdrawn', true));
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
        // 池仍标记为整池故障时，同一批次中先删后建的 latency 记录也不算撤下
        $pool = $weighted->app->pools()->get('tokyo');
        $pool->setDownSince('v4', $weighted->clock->now());
        $weighted->app->pools()->saveHealth($pool);

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
        self::assertNotContains('pool_withdrawn', $weighted->eventTypes());
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
        self::assertNotContains('pool_withdrawn', $t->eventTypes(), 'removing a pool from config is not a failure');
    }

    public function testReadsEachZoneWithTwoListCallsRegardlessOfPoolCount(): void
    {
        $pools = [];
        foreach (['tokyo' => 'ap-northeast-1', 'frankfurt' => 'eu-central-1', 'virginia' => 'us-east-1', 'ohio' => 'us-east-2',
            'oregon' => 'us-west-2', 'saopaulo' => 'sa-east-1', 'singapore' => 'ap-southeast-1', 'mumbai' => 'ap-south-1'] as $name => $region) {
            $pools[$name] = ['latency_region' => $region, 'max' => 5, 'launch' => [['provider' => 'aws', 'region' => $region, 'instance_type' => 't3.micro', 'ami' => 'ami-0abc', 'subnet_id' => 'subnet-0abc']]];
        }
        $t = new TestApp(['dns' => ['ipv6' => false], 'pools' => $pools]);
        $route53 = $t->network->route53;
        $i = 0;
        foreach (array_keys($pools) as $pool) {
            $t->addNode("web-{$pool}-a", $pool, Node::IN_SERVICE, '10.0.0.' . (++$i));
        }
        // 池记录后缀下的无关记录：读到但不管理
        $route53->add(RecordSet::multiValue('other.pool.app.example.com', 'A', 'manual', 60, ['10.9.9.9']));
        $t->app->dnsSync()->sync();

        $route53->listCalls = 0;
        self::assertFalse($t->app->dnsSync()->sync()->hasChanges());
        self::assertSame(2, $route53->listCalls, '1 for the public name + 1 for the pool record subtree');
        self::assertCount(8, $t->app->dnsSync()->publishedNodeIds());
        self::assertSame(['A manual 10.9.9.9'], $t->records('other.pool.app.example.com'));
    }

    public function testFallsBackToPerNameQueriesWhenThePoolSubtreeIsHuge(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $route53 = $t->network->route53;
        $route53->maxPageSize = 2;
        for ($i = 0; $i < 8; $i++) {
            $route53->add(RecordSet::multiValue("a{$i}.pool.app.example.com", 'A', 'manual', 60, ["10.9.9.{$i}"]));
        }
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $node = $t->node('web-tokyo-a');
        $node->transition(Node::DRAINING, $t->clock->now());
        $t->app->nodes()->save($node, $t->clock->now());

        $route53->listCalls = 0;
        $t->app->dnsSync()->sync();

        self::assertSame(['A web-tokyo-b 10.0.0.2'], $t->records(self::POOL), 'records are still found and updated through per-name queries');
        // latency 名称 1 次 + 子树 3 页后放弃 + 2 个池记录名各 1 次
        self::assertSame(6, $route53->listCalls);
    }

    public function testChangingPoolDomainRemovesRecordsUnderTheOldName(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();

        $t->reconfigure(['dns' => ['ipv6' => false, 'pool_domain' => '{pool}.lb.example.com']]);
        $t->app->dnsSync()->sync();

        self::assertSame([], $t->records(self::POOL), 'old pool records are found through the latency alias and deleted');
        self::assertSame(['A web-tokyo-a 10.0.0.1'], $t->records('tokyo.lb.example.com'));
        self::assertSame(['A dnslb-tokyo -> tokyo.lb.example.com'], $t->records('app.example.com'));
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
        self::assertSame([], $t->eventTypes(), 'waiting for takeover is not an alert');

        $this->expectException(DnsConflictException::class);
        $t->app->dnsSync()->zone('app.example.com')->fetchActual();
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
            'DELETE A app.example.com 203.0.113.9',
            'DELETE AAAA app.example.com 2001:db8:ffff::9',
            'UPSERT AAAA app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
            'UPSERT A app.example.com [dnslb-tokyo] region=ap-northeast-1 -> ' . self::POOL,
        ], self::describe($result->changes));
        self::assertCount(1, $route53->batches, 'takeover must be a single atomic batch');
        self::assertSame([
            'A dnslb-tokyo -> ' . self::POOL,
            'AAAA dnslb-tokyo -> ' . self::POOL,
            'TXT  "keep me"',
        ], $t->records('app.example.com'));
        self::assertSame(['dns_takeover'], $t->eventTypes(), 'the preview is not recorded');
        self::assertSame(
            '接管 app.example.com：删除了 2 条不由 dnslb 管理的记录（A 203.0.113.9、AAAA 2001:db8:ffff::9），解析改由 dnslb 管理',
            $t->app->events()->recent(1)[0]->message
        );
        self::assertFalse($t->app->dnsSync()->sync()->takeoverPending);
    }

    public function testForeignRecordAfterTakeoverKeepsLatencyRecords(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $t->app->dnsSync()->sync();
        // 接管后有人把普通 AAAA 记录加了回来
        $stray = self::simple('app.example.com', 'AAAA', '2001:db8:ffff::9');
        $t->network->route53->add($stray);
        $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');

        $result = $t->app->dnsSync()->sync();

        self::assertTrue($result->takeoverPending);
        self::assertSame(['UPSERT A tokyo.pool.app.example.com [web-tokyo-b] 10.0.0.2'], self::describe($result->changes));
        self::assertSame(['A dnslb-tokyo -> ' . self::POOL, 'AAAA  2001:db8:ffff::9'], $t->records('app.example.com'));
        self::assertSame(['dns_foreign_records'], $t->eventTypes());
        self::assertStringContainsString('出现了 1 条不由 dnslb 管理的记录（AAAA 2001:db8:ffff::9）', $t->app->events()->recent(1)[0]->message);

        $t->app->dnsSync()->sync();
        self::assertSame(['dns_foreign_records'], $t->eventTypes(), 'reported once');
        self::assertNotEmpty($t->logger->matching('receive traffic too'));

        unset($t->network->route53->records[$stray->key()]);
        $t->app->dnsSync()->sync();
        self::assertSame(['dns_foreign_records', 'dns_foreign_records_removed'], $t->eventTypes());
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
        self::assertStringStartsWith('原有节点所属的池已从配置删除（改名或替换了全部池）', $t->app->events()->recent(1)[0]->message);

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
