<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Provider\Instance;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\OrphanInstance;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\TestApp;

final class OrphanTrackerTest extends TestCase
{
    private TestApp $t;
    private FakeProvider $aws;

    protected function setUp(): void
    {
        $this->t = new TestApp(['pools' => ['tokyo' => ['min' => 0], 'frankfurt' => ['min' => 0]]]);
        $this->aws = new FakeProvider('aws');
        $this->t->app->providers()->set('aws', $this->aws);

        // 有节点引用的实例
        $node = $this->t->addNode('web-tokyo-a', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $node->instanceId = 'i-a';
        $this->t->app->nodes()->save($node, $this->t->clock->now());
        $this->instance('i-a', 'web-tokyo-a');
        // 建机结果未知、还没有实例 ID 的节点：同一节点 ID 的实例之后会被认领
        $this->t->addNode('web-tokyo-p', 'tokyo', Node::PROVISIONING, null, null, false);
        $this->instance('i-p', 'web-tokyo-p');
        // 没有节点引用：同一节点 ID 已经有另一台实例（换建机方式时多建的一台）
        $this->instance('i-dup', 'web-tokyo-a');
        // 没有节点引用：状态库里根本没有这个节点（例如断电丢失了提交）
        $this->instance('i-lost', 'web-tokyo-lost');
    }

    public function testUnreferencedInstancesAreReportedOnceAfterTheGracePeriod(): void
    {
        $tracker = $this->t->app->orphanTracker();
        $tracker->track(true);
        self::assertSame([], $tracker->confirmed());

        $this->t->clock->advance(899);
        $tracker->track(true);
        self::assertSame([], $tracker->confirmed());
        self::assertSame([], $this->t->eventTypes());

        $this->t->clock->advance(1);
        $tracker->track(true);
        self::assertSame(['i-dup', 'i-lost'], self::ids($tracker->confirmed()));
        self::assertSame(['orphan_instance', 'orphan_instance'], $this->t->eventTypes());
        self::assertStringContainsString('Dnslb::cleanupOrphans()', $this->t->app->events()->recent(1)[0]->message);

        $this->t->clock->advance(600);
        $tracker->track(true);
        self::assertCount(2, $this->t->eventTypes(), 'each orphan is reported once');
    }

    public function testInstanceThatIsReferencedAgainOrDeletedIsForgotten(): void
    {
        $tracker = $this->t->app->orphanTracker();
        $tracker->track(true);

        // 例如 rebuildState() 认领了它；另一台已被人工删除
        $this->t->addNode('web-tokyo-lost', 'tokyo', Node::IN_SERVICE, '10.0.0.9');
        $node = $this->t->node('web-tokyo-lost');
        $node->instanceId = 'i-lost';
        $this->t->app->nodes()->save($node, $this->t->clock->now());
        $this->aws->instances['i-dup']->status = Instance::GONE;
        $this->t->clock->advance(900);
        $tracker->track(true);

        self::assertSame([], $tracker->confirmed());
        self::assertSame([], $this->t->app->orphans()->all());
    }

    public function testListingFailureKeepsWhatWasFound(): void
    {
        $tracker = $this->t->app->orphanTracker();
        $tracker->track(true);
        $this->aws->listFails = true;
        $this->t->clock->advance(900);
        $tracker->track(true);

        self::assertSame(['i-dup', 'i-lost'], self::ids($tracker->confirmed()));
        self::assertNotEmpty($this->t->logger->matching('Cannot list instances on aws to look for orphans'));
    }

    public function testTickChecksAtMostEveryTenMinutes(): void
    {
        $tracker = $this->t->app->orphanTracker();
        $tracker->track();
        $this->instance('i-new', 'web-tokyo-new');
        $this->t->clock->advance(599);
        $tracker->track();
        $this->t->clock->advance(900);
        $tracker->track(true);

        self::assertSame(['i-dup', 'i-lost'], self::ids($tracker->confirmed()), 'i-new was first seen only by the last check');
    }

    public function testCleanupPreviewsByDefaultThenTerminates(): void
    {
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $lb = $this->t->lb(['aws' => $this->aws]);

        $preview = $lb->cleanupOrphans();
        self::assertTrue($preview->dryRun);
        self::assertSame(['i-dup', 'i-lost'], self::ids($preview->orphans));
        self::assertSame([], $preview->terminated);
        self::assertSame([], $this->aws->callsMatching('terminate'));
        self::assertSame(['i-dup', 'i-lost'], array_column($lb->status()['orphans'], 'instance_id'));

        $result = $lb->cleanupOrphans(false);
        self::assertSame(['i-dup', 'i-lost'], self::ids($result->terminated));
        self::assertSame(['terminate i-dup', 'terminate i-lost'], $this->aws->callsMatching('terminate'));
        self::assertSame([], $lb->status()['orphans']);
        self::assertSame(Instance::RUNNING, $this->aws->instances['i-a']->status, 'referenced instances are never touched');
        self::assertSame(Instance::RUNNING, $this->aws->instances['i-p']->status);
        $this->t->app->lock('tick')->release();
    }

    private function instance(string $id, string $nodeId): void
    {
        $this->aws->instances[$id] = new Instance('aws', $id, 'ap-northeast-1', Instance::RUNNING, null, null, $nodeId);
    }

    /**
     * @param OrphanInstance[] $orphans
     * @return string[]
     */
    private static function ids(array $orphans): array
    {
        return array_map(static function (OrphanInstance $orphan): string {
            return $orphan->instanceId;
        }, $orphans);
    }
}
