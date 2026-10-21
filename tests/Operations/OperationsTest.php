<?php

namespace Wcore\Dnslb\Tests\Operations;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Route53\Change;
use Wcore\Dnslb\Aws\Route53\RecordSet;
use Wcore\Dnslb\Operations\CheckItem;
use Wcore\Dnslb\Operations\OperationException;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\Dnslb;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * 通过 Dnslb 的公开方法测试各项操作。
 */
final class OperationsTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;
    private Dnslb $lb;

    protected function setUp(): void
    {
        $this->useApp(new TestApp([
            'dns' => ['ipv6' => false],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 3], 'frankfurt' => ['min' => 0]],
        ]));
    }

    protected function tearDown(): void
    {
        $this->t->app->lock('tick')->release();
    }

    public function testStatus(): void
    {
        $t = new TestApp();
        $t->addNode('web-tokyo-aaaaaa', 'tokyo', Node::IN_SERVICE, '10.0.0.1', '2001:db8::1');
        $sick = $t->addNode('web-tokyo-bbbbbb', 'tokyo', Node::IN_SERVICE, '10.0.0.2', '2001:db8::2');
        $sick->v6->healthy = false;
        $sick->v6->error = 'connect timeout';
        $t->app->nodes()->saveHealth($sick);
        $pool = $t->app->pools()->get('frankfurt');
        $pool->pausedUntil = $t->clock->now() + 600;
        $t->app->pools()->save($pool);
        $state = $t->app->pools()->get('tokyo');
        $state->manualPaused = true;
        $t->app->pools()->save($state);
        $t->app->meta()->set('frozen_v4', (string) ($t->clock->now() - 120));
        $t->app->eventRecorder()->warning('node_unhealthy', '节点 IPv6 连续 3 次健康检查失败', 'tokyo', 'web-tokyo-bbbbbb');

        $status = $t->lb()->status();

        self::assertSame('web', $status['service']);
        self::assertSame(['provider' => 'route53', 'name' => 'app.example.com', 'routing' => 'multivalue', 'ipv6' => true], $status['dns']);
        self::assertSame([['flag' => 'frozen', 'family' => 'v4', 'since' => $t->clock->now() - 120]], $status['flags']);

        [$tokyo, $frankfurt] = $status['pools'];
        self::assertSame('tokyo', $tokyo['name']);
        self::assertSame('ap-northeast-1', $tokyo['latency_region']);
        self::assertTrue($tokyo['manual_paused']);
        self::assertSame(['web-tokyo-aaaaaa', 'web-tokyo-bbbbbb'], array_column($tokyo['nodes'], 'node_id'));
        self::assertFalse($tokyo['nodes'][1]['v6_healthy']);
        self::assertSame('connect timeout', $tokyo['nodes'][1]['health_error']);
        self::assertTrue($frankfurt['paused']);
        self::assertFalse($frankfurt['manual_paused']);
        self::assertSame($t->clock->now() + 600, $frankfurt['paused_until']);

        self::assertSame('node_unhealthy', $status['events'][0]['type']);
        self::assertSame('web-tokyo-bbbbbb', $status['events'][0]['node_id']);
    }

    public function testTakeoverPreviewThenApply(): void
    {
        $old = new RecordSet('app.example.com', 'A');
        $old->ttl = 300;
        $old->values = ['203.0.113.9'];
        $this->t->network->route53->add($old);

        try {
            $this->lb->takeover(true);
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('No pool has in-service nodes yet', $e->getMessage());
        }

        $this->t->addNode('web-tokyo-aaaaaa', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $preview = $this->lb->takeover(true);
        self::assertTrue($preview->dryRun);
        self::assertContains('DELETE A app.example.com 203.0.113.9', self::describe($preview->changes));
        self::assertSame([], $this->t->network->route53->batches);

        $result = $this->lb->takeover();
        self::assertFalse($result->dryRun);
        self::assertSame(['C1'], $result->changeIds);
        self::assertSame(self::describe($preview->changes), self::describe($result->changes));
        self::assertSame(['A dnslb-tokyo -> tokyo.pool.app.example.com'], $this->t->records('app.example.com'));

        self::assertFalse($this->lb->takeover()->hasChanges(), 'nothing left to take over');
    }

    public function testScaleOutIsCappedByMax(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $result = $this->lb->scale('tokyo', 5);

        self::assertSame(5, $result->requested);
        self::assertSame(2, $result->allowed);
        self::assertCount(2, $result->created);
        self::assertSame(Node::BOOTING, $result->created[0]->state);
        self::assertSame([], $result->failed);
        self::assertCount(2, $this->aws->callsMatching('create'));
        self::assertSame($this->t->clock->now(), $this->t->app->pools()->get('tokyo')->lastScaleOutAt);

        $atMax = $this->lb->scale('tokyo', 1);
        self::assertSame(0, $atMax->allowed);
        self::assertSame([], $atMax->created);
    }

    public function testScaleOutReportsLaunchFailures(): void
    {
        $this->aws->failCreate = 'InsufficientInstanceCapacity';

        $result = $this->lb->scale('tokyo', 1);

        self::assertSame([], $result->created);
        self::assertCount(1, $result->failed);
        self::assertStringContainsString('InsufficientInstanceCapacity', (string) $result->failed[0]->lastError);
    }

    public function testScaleInDrainsAndUpdatesDnsImmediately(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $this->t->app->dnsSync()->sync();

        $dry = $this->lb->scale('tokyo', -1, true);
        self::assertCount(1, $dry->drained);
        self::assertCount(2, $this->t->records('tokyo.pool.app.example.com'), 'dry-run changes nothing');
        self::assertSame(Node::IN_SERVICE, $this->t->node($dry->drained[0]->nodeId)->state);

        $result = $this->lb->scale('tokyo', -5);

        self::assertSame(-5, $result->requested);
        self::assertSame(-1, $result->allowed, 'limited by min');
        self::assertCount(1, $result->drained);
        self::assertSame(Node::DRAINING, $this->t->node($result->drained[0]->nodeId)->state);
        self::assertCount(1, $this->t->records('tokyo.pool.app.example.com'));
        self::assertSame(0, $this->lb->scale('tokyo', -1)->allowed, 'already at min');
    }

    public function testManualScaleInPrefersUnhealthyNodesAndKeepsMinHealthy(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1', null, false);
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $this->t->addNode('web-tokyo-c', 'tokyo', Node::IN_SERVICE, '10.0.0.3');

        $result = $this->lb->scale('tokyo', -3);

        self::assertSame(-2, $result->allowed, 'min=1 healthy node must remain');
        self::assertSame(['web-tokyo-a', 'web-tokyo-c'], array_map(static function (Node $n): string {
            return $n->nodeId;
        }, $result->drained));
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-b')->state);
    }

    public function testScaleValidation(): void
    {
        try {
            $this->lb->scale('tokyo', 0);
            self::fail('Expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('delta must not be 0', $e->getMessage());
        }
        $this->expectExceptionMessage('Unknown pool: nope');
        $this->lb->scale('nope', 1);
    }

    public function testDrain(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $this->t->app->dnsSync()->sync();

        $node = $this->lb->drain('web-tokyo-a');

        self::assertSame(Node::DRAINING, $node->state);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
        self::assertSame(['A web-tokyo-b 10.0.0.2'], $this->t->records('tokyo.pool.app.example.com'));

        try {
            $this->lb->drain('web-tokyo-a');
            self::fail('Expected OperationException');
        } catch (OperationException $e) {
            self::assertStringContainsString('only in-service nodes can be drained', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->lb->drain('missing');
    }

    public function testReplaceKeepsGoingEvenThoughOldNodeIsHealthyAndAutohealIsOff(): void
    {
        $this->useApp(new TestApp([
            'dns' => ['ipv6' => false],
            'autoheal' => ['enabled' => false],
            'pools' => ['tokyo' => ['min' => 1, 'max' => 3], 'frankfurt' => ['min' => 0]],
        ]));
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');

        $replacement = $this->lb->replace('web-tokyo-a');

        $old = $this->t->node('web-tokyo-a');
        self::assertSame(Node::REPLACING, $old->state);
        self::assertTrue($old->replaceManual);
        self::assertSame($old->replacedBy, $replacement->nodeId);
        self::assertSame('web-tokyo-a', $replacement->replacementFor);

        $this->t->app->autoHealer()->run([]);
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-a')->state);
        $replacement->transition(Node::IN_SERVICE, $this->t->clock->now());
        $this->t->app->nodes()->save($replacement, $this->t->clock->now());
        $this->t->app->autoHealer()->run([]);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
    }

    public function testReplaceFailureLeavesNodeAlone(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->aws->failCreate = 'quota exceeded';

        try {
            $this->lb->replace('web-tokyo-a');
            self::fail('Expected OperationException');
        } catch (OperationException $e) {
            self::assertStringContainsString('quota exceeded', $e->getMessage());
        }
        self::assertSame(Node::IN_SERVICE, $this->t->node('web-tokyo-a')->state);
    }

    public function testPauseStopsAutomaticChangesAndResumeClearsAutoPause(): void
    {
        $this->lb->pause('tokyo');
        $this->lb->tick();
        self::assertSame([], $this->aws->calls, 'paused pool is not topped up to min');
        self::assertTrue($this->t->app->pools()->get('tokyo')->manualPaused);
        self::assertFalse($this->t->app->pools()->get('frankfurt')->manualPaused);

        $state = $this->t->app->pools()->get('tokyo');
        $state->pausedUntil = $this->t->clock->now() + 3600;
        $state->warmupFailures = 3;
        $this->t->app->pools()->save($state);

        $this->lb->resume();
        $state = $this->t->app->pools()->get('tokyo');
        self::assertFalse($state->manualPaused);
        self::assertNull($state->pausedUntil);
        self::assertSame(0, $state->warmupFailures);
        self::assertSame(['pool_manual_pause', 'pool_manual_resume', 'pool_manual_resume'], array_values(array_filter($this->t->eventTypes(), static function (string $type): bool {
            return strpos($type, 'pool_manual') === 0;
        })));

        $this->lb->tick();
        self::assertCount(1, $this->aws->callsMatching('create'));
    }

    public function testRebuildStateFromInstancesAndDns(): void
    {
        $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $this->t->addNode('web-tokyo-b', 'tokyo', Node::IN_SERVICE, '10.0.0.2');
        $this->t->app->dnsSync()->sync();
        // 模拟状态库丢失：换一个空库，云上和 DNS 中的内容保持不变
        $route53 = $this->t->network->route53;
        $this->useApp(new TestApp(['dns' => ['ipv6' => false], 'pools' => ['tokyo' => ['min' => 1, 'max' => 3], 'frankfurt' => ['min' => 0]]]));
        $this->t->network->route53 = $route53;
        foreach (['a' => '10.0.0.1', 'b' => '10.0.0.2', 'c' => '10.0.0.3'] as $suffix => $ip) {
            $this->aws->instances["i-{$suffix}"] = new Instance('aws', "i-{$suffix}", 'ap-northeast-1', Instance::RUNNING, $ip, null, "web-tokyo-{$suffix}", [
                'dnslb:service' => 'web',
                'dnslb:pool' => 'tokyo',
                'dnslb:node' => "web-tokyo-{$suffix}",
            ], 1600000000);
        }
        $this->aws->instances['i-x'] = new Instance('aws', 'i-x', 'ap-northeast-1', Instance::RUNNING, '10.0.0.9', null, null);

        $result = $this->lb->rebuildState();

        self::assertTrue($result->complete);
        self::assertSame(['web-tokyo-a', 'web-tokyo-b', 'web-tokyo-c'], array_map(static function (Node $n) {
            return $n->nodeId;
        }, $result->restored));
        self::assertCount(1, $result->warnings, 'instance without node id');
        $a = $this->t->node('web-tokyo-a');
        self::assertSame(Node::IN_SERVICE, $a->state);
        self::assertSame('i-a', $a->instanceId);
        self::assertTrue($a->v4->healthy);
        self::assertSame(1600000000, $a->createdAt);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-c')->state, 'not in DNS: drained');

        self::assertSame([], $this->lb->rebuildState()->restored);
        self::assertFalse($this->t->app->dnsSync()->sync()->hasChanges(), 'restored state matches DNS');
    }

    public function testCheckReport(): void
    {
        $report = $this->lb->check();

        $byName = [];
        foreach ($report->items as $item) {
            $byName[$item->name] = $item;
        }
        self::assertSame(CheckItem::OK, $byName['配置']->status);
        self::assertSame('服务 web，2 个池（tokyo, frankfurt）', $byName['配置']->message);
        self::assertSame(CheckItem::OK, $byName['Route 53 托管区域']->status);
        self::assertSame('Z1（example.com）', $byName['Route 53 托管区域']->message);
        self::assertSame(CheckItem::OK, $byName['探测机 IPv4 出口']->status);
        self::assertSame(CheckItem::WARN, $byName['邮件通知']->status);
        self::assertContains($byName['邮件通知'], $report->warnings());
        self::assertSame('可以查询实例（当前 0 台属于本服务）', $byName['EC2 账号 aws']->message);
        // 建机参数检查直接调用 EC2 接口，FakeNetwork 没有模拟，因此失败
        self::assertSame(CheckItem::FAIL, $byName['池 tokyo 建机方式 1（aws/ap-northeast-1）']->status);
        self::assertContains($byName['池 tokyo 建机方式 1（aws/ap-northeast-1）'], $report->failures());
        self::assertFalse($report->passed());
    }

    private function useApp(TestApp $t): void
    {
        $this->t = $t;
        $this->aws = $this->aws ?? new FakeProvider('aws');
        $this->lb = $t->lb(['aws' => $this->aws]);
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
