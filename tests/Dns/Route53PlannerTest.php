<?php

namespace Wcore\Dnslb\Tests\Dns;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Dns\Route53Planner;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\TestApp;

final class Route53PlannerTest extends TestCase
{
    public function testPublishesHealthyServingNodesAndLatencyRecords(): void
    {
        $t = new TestApp();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $t->addNode('web-tokyo-r', 'tokyo', Node::REPLACING, '10.0.0.2', '2001:db8::2');
        $t->addNode('web-tokyo-w', 'tokyo', Node::WARMING, '10.0.0.3', '2001:db8::3');
        $t->addNode('web-tokyo-d', 'tokyo', Node::DRAINING, '10.0.0.4', '2001:db8::4');
        $t->addNode('web-gone-a', 'gone', Node::IN_SERVICE, '10.0.9.1', null);

        $records = self::planner($t)->plan($t->clock->now());

        $keys = array_keys($records);
        sort($keys);
        self::assertSame([
            'app.example.com|AAAA|dnslb-tokyo',
            'app.example.com|A|dnslb-tokyo',
            'tokyo.pool.app.example.com|AAAA|web-tokyo-a',
            'tokyo.pool.app.example.com|AAAA|web-tokyo-r',
            'tokyo.pool.app.example.com|A|web-tokyo-a',
            'tokyo.pool.app.example.com|A|web-tokyo-r',
        ], $keys);

        $node = $records['tokyo.pool.app.example.com|AAAA|web-tokyo-a'];
        self::assertTrue($node->multiValueAnswer);
        self::assertNull($node->weight);
        self::assertSame(60, $node->ttl);
        self::assertSame(['2001:db8::1'], $node->values);

        $latency = $records['app.example.com|A|dnslb-tokyo'];
        self::assertSame('ap-northeast-1', $latency->region);
        self::assertSame('Z1', $latency->aliasTarget->hostedZoneId);
        self::assertSame('tokyo.pool.app.example.com', $latency->aliasTarget->dnsName);
        self::assertTrue($latency->aliasTarget->evaluateTargetHealth);
    }

    public function testWeightedRoutingAndIpv4Only(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false, 'pool_routing' => 'weighted', 'ttl' => 30]]);
        $node = $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $node->weight = 25;
        $t->app->nodes()->save($node, $t->clock->now());

        $records = self::planner($t)->plan($t->clock->now());

        self::assertSame(['app.example.com|A|dnslb-tokyo', 'tokyo.pool.app.example.com|A|web-tokyo-a'], array_keys($records));
        $record = $records['tokyo.pool.app.example.com|A|web-tokyo-a'];
        self::assertSame(25, $record->weight);
        self::assertFalse($record->multiValueAnswer);
        self::assertSame(30, $record->ttl);
    }

    public function testUnhealthyNodesAreLeftOut(): void
    {
        $t = new TestApp();
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $sick = $t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2', '2001:db8::2');
        $sick->v6->healthy = false;
        $t->app->nodes()->saveHealth($sick);

        $keys = array_keys(self::planner($t)->plan($t->clock->now()));

        self::assertContains('tokyo.pool.app.example.com|A|web-tokyo-b', $keys);
        self::assertNotContains('tokyo.pool.app.example.com|AAAA|web-tokyo-b', $keys);
        self::assertContains('tokyo.pool.app.example.com|AAAA|web-tokyo-a', $keys);
    }

    public function testWholePoolDownKeepsLastNodesDuringGraceThenFailsOver(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');
        $old = $t->addNode('web-tokyo-old', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $last = $t->addNode('web-tokyo-last', 'tokyo', Node::IN_SERVICE, '10.0.0.2');

        // web-tokyo-old 早就不健康（已经不在 DNS 中），web-tokyo-last 刚刚变为不健康，整池故障
        $old->v4->since = 1000;
        $t->app->nodes()->saveHealth($old);
        $downAt = $t->clock->now();
        $last->v4->healthy = false;
        $last->v4->since = $downAt;
        $t->app->nodes()->saveHealth($last);
        $pool = $t->app->pools()->get('tokyo');
        $pool->setDownSince('v4', $downAt);
        $t->app->pools()->saveHealth($pool);

        $planner = self::planner($t);
        $during = array_keys($planner->plan($downAt + 179));
        self::assertContains('tokyo.pool.app.example.com|A|web-tokyo-last', $during);
        self::assertNotContains('tokyo.pool.app.example.com|A|web-tokyo-old', $during);
        self::assertContains('app.example.com|A|dnslb-tokyo', $during);

        $after = array_keys($planner->plan($downAt + 180));
        self::assertSame([
            'app.example.com|A|dnslb-frankfurt',
            'frankfurt.pool.app.example.com|A|web-fra-a',
        ], $after);
    }

    public function testFailsOpenWhenEveryPoolWouldBeRemoved(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1', null, false);
        foreach (['tokyo', 'frankfurt'] as $name) {
            $pool = $t->app->pools()->get($name);
            $pool->setDownSince('v4', $t->clock->now() - 3600);
            $t->app->pools()->saveHealth($pool);
        }

        $keys = array_keys(self::planner($t)->plan($t->clock->now()));

        self::assertSame([
            'app.example.com|A|dnslb-frankfurt',
            'app.example.com|A|dnslb-tokyo',
            'frankfurt.pool.app.example.com|A|web-fra-a',
            'tokyo.pool.app.example.com|A|web-tokyo-a',
        ], $keys);
    }

    public function testPoolNotYetMarkedDownKeepsAllCandidates(): void
    {
        $t = new TestApp(['dns' => ['ipv6' => false]]);
        $t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $t->addNode('web-fra-a', 'frankfurt', Node::IN_SERVICE, '10.0.1.1');

        $keys = array_keys(self::planner($t)->plan($t->clock->now()));

        self::assertContains('tokyo.pool.app.example.com|A|web-tokyo-a', $keys);
    }

    public function testFingerprintIgnoresOrder(): void
    {
        $a = RecordSet::multiValue('x.example.com', 'A', 'n1', 60, ['1.1.1.1']);
        $b = RecordSet::multiValue('x.example.com', 'A', 'n2', 60, ['2.2.2.2']);

        self::assertSame(
            Route53Planner::fingerprint([$a->key() => $a, $b->key() => $b]),
            Route53Planner::fingerprint([$b->key() => $b, $a->key() => $a])
        );
        self::assertNotSame(Route53Planner::fingerprint([$a->key() => $a]), Route53Planner::fingerprint([$b->key() => $b]));
    }

    private static function planner(TestApp $t): Route53Planner
    {
        return new Route53Planner($t->config, $t->app->nodeSelector());
    }
}
