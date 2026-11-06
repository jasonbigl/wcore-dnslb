<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Cloudflare\DnsRecord;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Dnslb;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\FakeCloudflare;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * 使用 Cloudflare DNS 时，通过 Dnslb 的公开方法走完整流程：建机 → 健康检查 → 发布 → 排空 → 删除机器。
 */
final class CloudflareEndToEndTest extends TestCase
{
    private const NAME = 'app.example.com';

    private TestApp $t;
    private FakeProvider $aws;
    private Dnslb $lb;

    protected function setUp(): void
    {
        $this->t = new TestApp([
            'dns' => ['ipv6' => false],
            'drain' => ['min' => 60, 'max' => 120],
            'pools' => ['tokyo' => ['min' => 2], 'frankfurt' => ['min' => 1]],
        ], true);
        $this->aws = new FakeProvider('aws');
        $this->lb = $this->t->lb(['aws' => $this->aws]);
    }

    protected function tearDown(): void
    {
        $this->t->app->lock('tick')->release();
    }

    public function testNodesFromAllPoolsAreServedRoundRobinAndRemovedOnFailure(): void
    {
        $this->lb->tick();
        $nodes = $this->t->app->nodes()->live();
        self::assertCount(3, $nodes);
        foreach ($nodes as $i => $node) {
            $this->aws->boot($node->nodeId, "10.0.0.{$i}");
        }
        $this->tick();
        self::assertSame([], $this->contents(), 'nothing is published before nodes pass health checks');

        $this->health(2);
        $this->tick();
        self::assertSame(['A 10.0.0.0', 'A 10.0.0.1', 'A 10.0.0.2'], $this->contents());
        $record = $this->t->network->cloudflare->recordsNamed(self::NAME)[0];
        self::assertTrue($record->proxied);

        // 节点故障：健康检查连续失败后立即摘除，不等 tick
        $this->t->network->unreachable['10.0.0.1'] = true;
        $this->health(2);
        self::assertSame(['A 10.0.0.0', 'A 10.0.0.2'], $this->contents());

        // 手动排空：确认记录已删除后才开始计算排空时长
        $drained = $this->t->app->nodes()->find($nodes[2]->nodeId);
        $this->lb->drain($drained->nodeId);
        self::assertSame(['A 10.0.0.0'], $this->contents());
        $this->tick();
        $this->t->clock->advance(59);
        $this->tick();
        self::assertSame(Node::DRAINING, $this->t->node($drained->nodeId)->state, 'drain.max counts from removal');
        $this->t->clock->advance(1);
        $this->tick();
        self::assertSame(Node::TERMINATED, $this->t->node($drained->nodeId)->state);
        self::assertContains('terminate ' . $this->aws->instanceOf($drained->nodeId)->id, $this->aws->calls);
    }

    public function testSingleNodeServiceIsReplacedWithoutEverLosingItsRecord(): void
    {
        $this->t = new TestApp(['dns' => ['ipv6' => false], 'pools' => ['tokyo' => ['min' => 1], 'frankfurt' => ['min' => 0]]], true);
        $this->lb = $this->t->lb(['aws' => $this->aws]);
        $this->lb->tick();
        $old = $this->t->app->nodes()->live()[0]->nodeId;
        $this->aws->boot($old, '10.0.0.1');
        $this->tick();
        $this->health(2);
        $this->tick();
        self::assertSame(['A 10.0.0.1'], $this->contents());

        // 应用故障：唯一的节点不健康，记录保留（没有其他节点可切），不冻结
        $this->t->network->status['10.0.0.1'] = 503;
        $this->health(3);
        self::assertFalse($this->t->node($old)->v4->healthy);
        self::assertNull($this->t->app->meta()->get('frozen_v4'));
        self::assertSame(['A 10.0.0.1'], $this->contents());

        // 持续 unhealthy_for 后建替补，替补上线后切换并排空旧节点
        $this->t->clock->advance(300);
        $this->tick();
        $replacement = (string) $this->t->node($old)->replacedBy;
        self::assertNotSame('', $replacement);
        $this->aws->boot($replacement, '10.0.0.2');
        $this->tick();
        $this->health(2);
        $this->tick();

        self::assertSame(Node::IN_SERVICE, $this->t->node($replacement)->state);
        self::assertSame(Node::DRAINING, $this->t->node($old)->state);
        self::assertSame(['A 10.0.0.2'], $this->contents());
        self::assertContains('autoheal_replace', $this->t->eventTypes());
        self::assertNotContains('global_freeze', $this->t->eventTypes());
        self::assertNotContains('autoheal_systemic', $this->t->eventTypes());
    }

    public function testTakeoverAndCheckWithoutAws(): void
    {
        $this->t->network->cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.9', true));
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $this->lb->tick();
        self::assertSame(['A 203.0.113.9'], $this->contents(), 'existing records are left alone before takeover');

        $result = $this->lb->takeover();
        self::assertCount(2, $result->changes);
        self::assertSame(['A 10.0.0.1'], $this->contents());

        $names = array_map(static function ($item): string {
            return $item->name;
        }, $this->lb->check()->items);
        self::assertContains('Cloudflare 区域', $names);
        self::assertContains('Cloudflare 现有记录', $names);
        self::assertNotContains('Route 53 托管区域', $names);
        self::assertSame([
            'provider' => 'cloudflare',
            'zones' => [['name' => self::NAME, 'zone_id' => FakeCloudflare::ZONE_ID, 'takeover_pending' => false]],
            'routing' => 'proxied',
            'ipv6' => false,
        ], $this->lb->status()['dns']);
    }

    public function testNodesComingUpBeforeTakeoverAreReportedAsNotServing(): void
    {
        $this->t->network->cloudflare->add(new DnsRecord('A', self::NAME, '203.0.113.9', true));
        $this->lb->tick();
        foreach ($this->t->app->nodes()->live() as $i => $node) {
            $this->aws->boot($node->nodeId, "10.0.0.{$i}");
        }
        $this->tick();
        $this->health(2);
        $this->tick();

        self::assertSame(['A 203.0.113.9'], $this->contents());
        $messages = $this->messages('node_in_service');
        self::assertCount(3, $messages);
        self::assertMatchesRegularExpression(
            '/^节点通过健康检查（从创建到上线 \d+ 秒）。app\.example\.com 上还有不由 dnslb 管理的记录，调用 Dnslb::takeover\(\) 接管之前不会接到流量$/u',
            $messages[0]
        );

        $this->lb->takeover();
        self::assertFalse($this->t->app->foreignRecords()['app.example.com']->takeoverPending(), 'cleared by the takeover itself, before the next sync');

        $created = $this->lb->scale('tokyo', 1)->created[0];
        $this->aws->boot($created->nodeId, '10.0.0.9');
        $this->tick();
        $this->health(2);
        $this->tick();
        self::assertMatchesRegularExpression('/^节点通过健康检查，加入 DNS（从创建到上线 \d+ 秒）$/u', $this->messages('node_in_service')[3]);
        self::assertContains('A 10.0.0.9', $this->contents());
    }

    public function testRebuildStateUsesCloudflareRecords(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->t->app->dnsSync()->sync();
        $cloudflare = $this->t->network->cloudflare;

        $fresh = new TestApp(['dns' => ['ipv6' => false]], true);
        $fresh->network->cloudflare = $cloudflare;
        foreach (['a' => '10.0.0.1', 'b' => '10.0.0.2'] as $suffix => $ip) {
            $this->aws->instances["i-{$suffix}"] = new Instance('aws', "i-{$suffix}", 'ap-northeast-1', Instance::RUNNING, $ip, null, "web-tokyo-{$suffix}", [
                'dnslb:service' => 'web',
                'dnslb:pool' => 'tokyo',
                'dnslb:node' => "web-tokyo-{$suffix}",
            ]);
        }

        $result = $fresh->lb(['aws' => $this->aws])->rebuildState();

        self::assertCount(2, $result->restored);
        self::assertSame(Node::IN_SERVICE, $fresh->node('web-tokyo-a')->state);
        self::assertSame(Node::DRAINING, $fresh->node('web-tokyo-b')->state, 'not in DNS');
        $fresh->app->lock('tick')->release();
    }

    /**
     * @return string[] 对外域名下的记录，例如 "A 10.0.0.1"
     */
    private function contents(): array
    {
        $result = array_map(static function (DnsRecord $r): string {
            return "{$r->type} {$r->content}";
        }, $this->t->network->cloudflare->recordsNamed(self::NAME));
        sort($result);
        return $result;
    }

    /**
     * @return string[] 指定类型事件的内容，按时间顺序
     */
    private function messages(string $type): array
    {
        $events = array_filter($this->t->app->events()->recent(1000), static function ($event) use ($type): bool {
            return $event->type === $type;
        });
        return array_reverse(array_values(array_map(static function ($event): string {
            return $event->message;
        }, $events)));
    }

    private function health(int $rounds): void
    {
        for ($i = 0; $i < $rounds; $i++) {
            $this->lb->health(0, true);
            $this->t->clock->advance(10);
        }
    }

    private function tick(): void
    {
        self::assertTrue($this->lb->tick());
        $this->t->clock->advance(60);
    }
}
