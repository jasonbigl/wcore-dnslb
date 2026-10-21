<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

/**
 * 通过完整的 tick 流程验证各步骤的配合（自动修复在维持 min 之前，不会重复建机）。
 */
final class TickRunnerTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;

    protected function setUp(): void
    {
        $this->t = new TestApp([
            'dns' => ['ipv6' => false],
            'autoheal' => ['unhealthy_for' => 300],
            'pools' => ['tokyo' => ['min' => 2, 'max' => 3], 'frankfurt' => ['min' => 0]],
        ]);
        $this->aws = new FakeProvider('aws');
        $this->t->app->providers()->set('aws', $this->aws);
        foreach (['a', 'b'] as $i => $suffix) {
            $node = $this->t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, "10.0.0.{$i}");
            $this->aws->instances["i-{$suffix}"] = new Instance('aws', "i-{$suffix}", 'ap-northeast-1', Instance::RUNNING, $node->ipv4, null, $node->nodeId);
            $node->instanceId = "i-{$suffix}";
            $node->createdAt -= 3600;
            $this->t->app->nodes()->save($node, $this->t->clock->now());
        }
    }

    public function testUnhealthyNodeIsReplacedThenRetired(): void
    {
        $a = $this->t->node('web-tokyo-a');
        $a->v4->healthy = false;
        $a->v4->since = $this->t->clock->now() - 600;
        $a->v4->error = 'unexpected HTTP status 500';
        $this->t->app->nodes()->saveHealth($a);

        $this->tick();
        self::assertSame(Node::REPLACING, $this->t->node('web-tokyo-a')->state);
        self::assertCount(1, $this->aws->callsMatching('create'), 'replacement only; min is satisfied by it');
        self::assertSame(['A web-tokyo-b 10.0.0.1'], $this->t->records('tokyo.pool.app.example.com'));

        $replacementId = (string) $this->t->node('web-tokyo-a')->replacedBy;
        $this->aws->boot($replacementId, '10.0.0.9');
        $this->tick();
        self::assertSame(Node::WARMING, $this->t->node($replacementId)->state);

        $replacement = $this->t->node($replacementId);
        $replacement->v4->healthy = true;
        $this->t->app->nodes()->saveHealth($replacement);
        $this->tick();

        self::assertSame(Node::IN_SERVICE, $this->t->node($replacementId)->state);
        self::assertSame(Node::DRAINING, $this->t->node('web-tokyo-a')->state);
        $expected = ['A web-tokyo-b 10.0.0.1', "A {$replacementId} 10.0.0.9"];
        sort($expected);
        self::assertSame($expected, $this->t->records('tokyo.pool.app.example.com'));
        self::assertCount(1, $this->aws->callsMatching('create'));
    }

    public function testLostNodeIsReplacedOnceNotTwice(): void
    {
        $this->aws->instances['i-a']->status = Instance::GONE;

        $this->tick();

        self::assertSame(Node::TERMINATED, $this->t->node('web-tokyo-a')->state);
        self::assertCount(1, $this->aws->callsMatching('create'));
        self::assertContains('autoheal_replace', $this->t->eventTypes());
    }

    private function tick(): void
    {
        $this->t->app->tickRunner()->run(false);
        $this->t->clock->advance(60);
    }
}
