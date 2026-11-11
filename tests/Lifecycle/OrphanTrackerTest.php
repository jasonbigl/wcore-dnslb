<?php

namespace Wcore\Dnslb\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Route53\RecordSet;
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
        $this->build(false);
    }

    /**
     * @param bool $elasticIp tokyo 池的建机方式是否使用弹性 IP
     */
    private function build(bool $elasticIp): void
    {
        $this->t = new TestApp(['pools' => ['tokyo' => ['min' => 0, 'launch' => [['elastic_ip' => $elasticIp]]], 'frankfurt' => ['min' => 0]]]);
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

    public function testCleanupSkipsInstancesWhoseNodeIsStillInDns(): void
    {
        // 状态库里没有的节点仍在 DNS 中（例如状态库从备份恢复）；状态库里有的 web-tokyo-a 也在 DNS 中
        $route53 = $this->t->network->route53;
        $route53->add(RecordSet::multiValue('tokyo.pool.app.example.com', 'A', 'web-tokyo-lost', 60, ['10.0.0.9']));
        $route53->add(RecordSet::multiValue('tokyo.pool.app.example.com', 'A', 'web-tokyo-a', 60, ['10.0.0.1']));
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $lb = $this->t->lb(['aws' => $this->aws]);

        $preview = $lb->cleanupOrphans();
        self::assertSame(['i-dup'], self::ids($preview->orphans), 'the record belongs to the known node, not to its duplicate');
        self::assertSame(['i-lost'], array_keys($preview->skipped));
        self::assertStringContainsString('仍在 DNS 中', $preview->skipped['i-lost']);
        self::assertStringContainsString('rebuildState()', $preview->skipped['i-lost']);

        $result = $lb->cleanupOrphans(false);
        self::assertSame(['i-dup'], self::ids($result->terminated));
        self::assertSame(['i-lost'], array_keys($result->skipped));
        self::assertSame(['terminate i-dup'], $this->aws->callsMatching('terminate'));
        self::assertSame(Instance::RUNNING, $this->aws->instances['i-lost']->status);
        $this->t->app->lock('tick')->release();
    }

    public function testCleanupDeletesNothingWhenDnsCannotBeRead(): void
    {
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $this->t->network->route53->denied = true;
        $lb = $this->t->lb(['aws' => $this->aws]);

        $result = $lb->cleanupOrphans(false);

        self::assertSame([], $result->orphans);
        self::assertSame(['i-dup', 'i-lost'], array_keys($result->skipped));
        self::assertStringContainsString('读不到 DNS 记录', $result->skipped['i-dup']);
        self::assertSame([], $this->aws->callsMatching('terminate'));
        $this->t->app->lock('tick')->release();
    }

    public function testUnreferencedElasticIpsAreReportedOnceAfterTheGracePeriod(): void
    {
        $this->build(true);
        // 存活节点名下的地址（包括多申请出来的一个）不算孤儿：节点结束时会一并释放
        $this->aws->addAddress('eipalloc-a', '198.51.100.1', 'web-tokyo-a', 'i-a');
        $this->aws->addAddress('eipalloc-a2', '198.51.100.2', 'web-tokyo-a');
        $this->aws->addAddress('eipalloc-p', '198.51.100.3', 'web-tokyo-p');
        // 节点已经不在了；以及没有节点标签的
        $this->aws->addAddress('eipalloc-gone', '198.51.100.4', 'web-tokyo-gone');
        $this->aws->addAddress('eipalloc-untagged', '198.51.100.5', null);
        $tracker = $this->t->app->orphanTracker();

        $tracker->track(true);
        $this->t->clock->advance(899);
        $tracker->track(true);
        self::assertSame([], $tracker->confirmed());

        $this->t->clock->advance(1);
        $tracker->track(true);

        $addresses = array_values(array_filter($tracker->confirmed(), static function (OrphanInstance $orphan): bool {
            return $orphan->isAddress();
        }));
        self::assertSame(['eipalloc-gone', 'eipalloc-untagged'], self::ids($addresses));
        self::assertSame('web-tokyo-gone', $addresses[0]->nodeId);
        self::assertSame(['list-addresses ap-northeast-1'], array_values(array_unique($this->aws->callsMatching('list-addresses'))), 'only regions that use elastic IPs');
        self::assertSame(2, count(array_keys($this->t->eventTypes(), 'orphan_address', true)));
        $event = array_values(array_filter($this->t->app->events()->recent(10), static function ($event): bool {
            return $event->type === 'orphan_address';
        }))[0];
        self::assertStringContainsString('弹性 IP eipalloc-', $event->message);
        self::assertStringContainsString('Dnslb::cleanupOrphans()', $event->message);
        self::assertSame('address', $event->context['kind']);

        $this->t->clock->advance(600);
        $tracker->track(true);
        self::assertSame(2, count(array_keys($this->t->eventTypes(), 'orphan_address', true)), 'each address is reported once');

        // 实例孤儿照常发现，两类记录互不影响
        self::assertSame(['i-dup', 'i-lost'], self::ids(array_values(array_filter($tracker->confirmed(), static function (OrphanInstance $orphan): bool {
            return !$orphan->isAddress();
        }))));
    }

    public function testElasticIpListingFailureKeepsWhatWasFound(): void
    {
        $this->build(true);
        $this->aws->addAddress('eipalloc-gone', '198.51.100.4', 'web-tokyo-gone');
        $tracker = $this->t->app->orphanTracker();
        $tracker->track(true);
        $this->aws->listAddressesFails = true;
        $this->t->clock->advance(900);
        $tracker->track(true);

        self::assertContains('eipalloc-gone', self::ids($tracker->confirmed()));
        self::assertNotEmpty($this->t->logger->matching('Cannot list elastic IPs on aws to look for orphans'));

        // 释放之后不再是孤儿
        $this->aws->listAddressesFails = false;
        unset($this->aws->addresses['eipalloc-gone']);
        $tracker->track(true);
        self::assertNotContains('eipalloc-gone', self::ids($tracker->confirmed()));
    }

    public function testElasticIpsAreNotListedUnlessTheyAreInUse(): void
    {
        $tracker = $this->t->app->orphanTracker();
        $tracker->track(true);
        self::assertSame([], $this->aws->callsMatching('list-addresses'), 'no elastic_ip in the config and no node uses one');

        // 关掉开关之后，还在用弹性 IP 的节点所在区域仍然检查
        $node = $this->t->node('web-tokyo-a');
        $node->elasticIp = true;
        $this->t->app->nodes()->save($node, $this->t->clock->now());
        $tracker->track(true);
        self::assertSame(['list-addresses x'], $this->aws->callsMatching('list-addresses'));
    }

    public function testCleanupReleasesOrphanAddresses(): void
    {
        $this->build(true);
        // 未关联任何实例
        $this->aws->addAddress('eipalloc-free', '198.51.100.4', 'web-tokyo-gone');
        // 关联着本次要删除的孤儿实例 i-lost
        $this->aws->addAddress('eipalloc-lost', '198.51.100.5', 'web-tokyo-lost', 'i-lost');
        // 关联着别的机器
        $this->aws->addAddress('eipalloc-busy', '198.51.100.6', 'web-tokyo-other', 'i-foreign');
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $lb = $this->t->lb(['aws' => $this->aws]);

        $preview = $lb->cleanupOrphans();
        self::assertSame(['i-dup', 'i-lost', 'eipalloc-free', 'eipalloc-lost'], self::ids($preview->orphans));
        self::assertSame(['eipalloc-busy'], array_keys($preview->skipped));
        self::assertStringContainsString('仍关联着实例 i-foreign', $preview->skipped['eipalloc-busy']);
        self::assertSame([], $this->aws->callsMatching('release-address'));
        self::assertSame('address', $lb->status()['orphans'][2]['kind']);

        $result = $lb->cleanupOrphans(false);
        self::assertSame(['i-dup', 'i-lost', 'eipalloc-free', 'eipalloc-lost'], self::ids($result->terminated));
        self::assertSame(['eipalloc-busy'], array_keys($result->skipped));
        self::assertSame([], $result->failed);
        self::assertSame(['release-address eipalloc-free', 'release-address eipalloc-lost detach'], $this->aws->callsMatching('release-address'));
        self::assertSame(['eipalloc-busy'], array_keys($this->aws->addresses));
        self::assertSame(['eipalloc-busy'], array_column($lb->status()['orphans'], 'instance_id'));
        self::assertContains('orphan_address_released', $this->t->eventTypes());
        $this->t->app->lock('tick')->release();
    }

    public function testCleanupKeepsAddressesWhoseNodeIsStillInDns(): void
    {
        $this->build(true);
        $this->t->network->route53->add(RecordSet::multiValue('tokyo.pool.app.example.com', 'A', 'web-tokyo-lost', 60, ['198.51.100.5']));
        $this->aws->addAddress('eipalloc-lost', '198.51.100.5', 'web-tokyo-lost', 'i-lost');
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $lb = $this->t->lb(['aws' => $this->aws]);

        $result = $lb->cleanupOrphans(false);

        self::assertSame(['i-dup'], self::ids($result->terminated));
        self::assertSame(['i-lost', 'eipalloc-lost'], array_keys($result->skipped));
        self::assertStringContainsString('仍在 DNS 中', $result->skipped['eipalloc-lost']);
        self::assertSame([], $this->aws->callsMatching('release-address'));
        $this->t->app->lock('tick')->release();
    }

    public function testCleanupReportsAddressesThatCouldNotBeReleased(): void
    {
        $this->build(true);
        $this->aws->addAddress('eipalloc-free', '198.51.100.4', 'web-tokyo-gone');
        $this->t->app->orphanTracker()->track(true);
        $this->t->clock->advance(900);
        $this->aws->releaseFails = 'UnauthorizedOperation: not authorized';
        $lb = $this->t->lb(['aws' => $this->aws]);

        $result = $lb->cleanupOrphans(false);

        self::assertSame(['eipalloc-free'], array_keys($result->failed));
        self::assertStringContainsString('UnauthorizedOperation', $result->failed['eipalloc-free']);
        self::assertContains('eipalloc-free', array_column($lb->status()['orphans'], 'instance_id'), 'still listed for the next run');
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
