<?php

namespace Wcore\Dnslb\Tests\State;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Application;
use Wcore\Dnslb\State\AlertLog;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Event;
use Wcore\Dnslb\State\EventRepository;
use Wcore\Dnslb\State\Meta;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\State\NodeRepository;
use Wcore\Dnslb\State\PoolRepository;
use Wcore\Dnslb\State\PoolState;
use Wcore\Dnslb\State\Schema;
use Wcore\Dnslb\Support\NullLogger;
use Wcore\Dnslb\Tests\Support\TestApp;

final class StateTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = Database::open(':memory:');
    }

    public function testMigratesToLatestVersion(): void
    {
        self::assertSame(count(Schema::migrations()), $this->db->schemaVersion());
    }

    public function testReopeningFileDatabaseKeepsDataAndUsesWal(): void
    {
        $dir = sys_get_temp_dir() . '/dnslb-test-' . bin2hex(random_bytes(4));
        $path = "{$dir}/nested/state.sqlite";
        try {
            $repo = new NodeRepository(Database::open($path));
            $repo->insert(new Node('web-tokyo-aaaaaa', 'tokyo', 'linode', 'ap-northeast', 0, 10, 100), 100);

            $db = Database::open($path);
            self::assertSame('wal', $db->pdo()->query('PRAGMA journal_mode')->fetchColumn());
            self::assertSame(2, (int) $db->pdo()->query('PRAGMA synchronous')->fetchColumn(), 'FULL');
            self::assertNotNull((new NodeRepository($db))->find('web-tokyo-aaaaaa'));
        } finally {
            foreach (glob("{$dir}/nested/*") ?: [] as $file) {
                unlink($file);
            }
            @rmdir("{$dir}/nested");
            @rmdir($dir);
        }
    }

    public function testConcurrentFirstOpensAllSucceed(): void
    {
        $path = sys_get_temp_dir() . '/dnslb-open-' . bin2hex(random_bytes(4)) . '.sqlite';
        // 几个进程在同一时刻首次打开同一个新文件（tick、health 被 cron 同时启动）
        $code = 'require $argv[1]; time_sleep_until((float) $argv[3]); Wcore\\Dnslb\\State\\Database::open($argv[2]); echo "ok";';
        $startAt = (string) (microtime(true) + 0.5);
        $processes = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $processes[] = proc_open(
                    [PHP_BINARY, '-r', $code, dirname(__DIR__, 2) . '/vendor/autoload.php', $path, $startAt],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes[$i]
                );
            }
            foreach ($processes as $i => $process) {
                $output = stream_get_contents($pipes[$i][1]) . stream_get_contents($pipes[$i][2]);
                self::assertSame(0, proc_close($process), $output);
                self::assertSame('ok', $output);
            }
            self::assertSame(count(Schema::migrations()), Database::open($path)->schemaVersion());
        } finally {
            foreach (glob($path . '*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function testDryRunReadsTheStateFileWithoutChangingIt(): void
    {
        $path = sys_get_temp_dir() . '/dnslb-ro-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            // 尚未迁移、仍是默认日志模式的库
            $pdo = new \PDO('sqlite:' . $path);
            foreach (Schema::migrations()[0] as $sql) {
                $pdo->exec($sql);
            }
            $pdo->exec("INSERT INTO nodes (node_id, pool, state, provider, provider_region, launch_index, weight, created_at, updated_at, state_changed_at)
                VALUES ('web-tokyo-old', 'tokyo', 'in_service', 'aws', 'x', 0, 10, 1, 1, 1)");
            unset($pdo);
            $before = md5_file($path);

            $app = new Application(TestApp::config(['state' => $path]), 'tick', true, ['logger' => new NullLogger()]);

            self::assertNotNull($app->nodes()->find('web-tokyo-old'));
            self::assertSame($before, md5_file($path));
            $pdo = new \PDO('sqlite:' . $path);
            self::assertSame(0, (int) $pdo->query('PRAGMA user_version')->fetchColumn(), 'not migrated');
            self::assertSame('delete', $pdo->query('PRAGMA journal_mode')->fetchColumn(), 'journal mode untouched');

            $this->expectException(\PDOException::class);
            Database::openReadOnly($path)->execute("DELETE FROM nodes");
        } finally {
            foreach (glob($path . '*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function testNodeRoundTrip(): void
    {
        $repo = new NodeRepository($this->db);
        $node = new Node('web-tokyo-aaaaaa', 'tokyo', 'linode', 'ap-northeast', 0, 10, 1000);
        $repo->insert($node, 1000);

        $node->instanceId = '12345';
        $node->ipv4 = '203.0.113.5';
        $node->ipv6 = '2001:db8::5';
        $node->transition(Node::WARMING, 1100);
        $node->v4->healthy = true;
        $node->v4->passes = 2;
        $node->v4->since = 1150;
        $node->v6->fails = 3;
        $node->v6->error = 'connect timeout';
        $node->probedAt = 1150;
        $node->replacementFor = 'web-tokyo-zzzzzz';
        $repo->save($node, 1200);
        $repo->saveHealth($node);

        $loaded = $repo->find('web-tokyo-aaaaaa');
        self::assertNotNull($loaded);
        self::assertSame(Node::WARMING, $loaded->state);
        self::assertSame('12345', $loaded->instanceId);
        self::assertSame('2001:db8::5', $loaded->ip(Node::FAMILY_V6));
        self::assertTrue($loaded->health(Node::FAMILY_V4)->healthy);
        self::assertSame(2, $loaded->v4->passes);
        self::assertSame(1150, $loaded->v4->since);
        self::assertFalse($loaded->v6->healthy);
        self::assertSame(3, $loaded->v6->fails);
        self::assertSame('connect timeout', $loaded->v6->error);
        self::assertSame('web-tokyo-zzzzzz', $loaded->replacementFor);
        self::assertSame(1000, $loaded->createdAt);
        self::assertSame(1100, $loaded->stateChangedAt);
        self::assertSame(1200, $loaded->updatedAt);
        self::assertSame(50, $loaded->stateAge(1150));
        self::assertNull($repo->find('missing'));
    }

    public function testQueriesByStateAndPool(): void
    {
        $repo = new NodeRepository($this->db);
        $states = [
            ['web-tokyo-a', 'tokyo', Node::IN_SERVICE],
            ['web-tokyo-b', 'tokyo', Node::DRAINING],
            ['web-tokyo-c', 'tokyo', Node::TERMINATED],
            ['web-fra-a', 'frankfurt', Node::IN_SERVICE],
        ];
        foreach ($states as $i => [$id, $pool, $state]) {
            $node = new Node($id, $pool, 'aws', 'eu-central-1', 0, 10, 100 + $i);
            $node->transition($state, 100 + $i);
            $repo->insert($node, 100 + $i);
        }

        self::assertSame(['web-tokyo-a', 'web-tokyo-b'], self::ids($repo->liveInPool('tokyo')));
        self::assertSame(['web-tokyo-a', 'web-fra-a'], self::ids($repo->byStates([Node::IN_SERVICE])));
        self::assertSame(['web-fra-a'], self::ids($repo->byStates([Node::IN_SERVICE], 'frankfurt')));
        self::assertCount(3, $repo->live());

        self::assertSame(1, $repo->purgeTerminated(1000));
        self::assertNull($repo->find('web-tokyo-c'));
    }

    public function testHealthAndLifecycleWritesDoNotOverwriteEachOther(): void
    {
        $repo = new NodeRepository($this->db);
        $repo->insert(new Node('web-tokyo-a', 'tokyo', 'aws', 'x', 0, 10, 100), 100);

        // 两个进程各自读到同一份旧数据
        $tick = $repo->find('web-tokyo-a');
        $health = $repo->find('web-tokyo-a');

        $health->v4->healthy = true;
        $health->v4->passes = 2;
        $repo->saveHealth($health);

        $tick->transition(Node::IN_SERVICE, 200);
        $repo->save($tick, 200);

        $loaded = $repo->find('web-tokyo-a');
        self::assertSame(Node::IN_SERVICE, $loaded->state);
        self::assertTrue($loaded->v4->healthy, 'lifecycle save must not clobber health fields');
        self::assertSame(2, $loaded->v4->passes);

        $pools = new PoolRepository($this->db);
        $tickPool = $pools->get('tokyo');
        $healthPool = $pools->get('tokyo');
        $healthPool->v4DownSince = 150;
        $pools->saveHealth($healthPool);
        $tickPool->lastScaleOutAt = 160;
        $pools->save($tickPool);

        $loadedPool = $pools->get('tokyo');
        self::assertSame(150, $loadedPool->v4DownSince);
        self::assertSame(160, $loadedPool->lastScaleOutAt);
    }

    public function testSavingUnknownNodeFails(): void
    {
        $this->expectException(\RuntimeException::class);
        (new NodeRepository($this->db))->save(new Node('ghost', 'tokyo', 'aws', 'x', 0, 10, 1), 1);
    }

    public function testPoolState(): void
    {
        $repo = new PoolRepository($this->db);
        $fresh = $repo->get('tokyo');
        self::assertNull($fresh->lastScaleOutAt);
        self::assertFalse($fresh->isPaused(100));

        $fresh->lastScaleOutAt = 500;
        $fresh->setDownSince(Node::FAMILY_V6, 450);
        $fresh->pausedUntil = 900;
        $fresh->warmupFailures = 2;
        $repo->save($fresh);
        $repo->saveHealth($fresh);
        $repo->save(new PoolState('frankfurt'));

        $loaded = $repo->get('tokyo');
        self::assertSame(500, $loaded->lastScaleOutAt);
        self::assertSame(450, $loaded->downSince(Node::FAMILY_V6));
        self::assertNull($loaded->downSince(Node::FAMILY_V4));
        self::assertTrue($loaded->isPaused(899));
        self::assertFalse($loaded->isPaused(900));
        self::assertSame(2, $loaded->warmupFailures);
        self::assertSame(['frankfurt', 'tokyo'], array_keys($repo->all()));
    }

    public function testEventsAndNotifications(): void
    {
        $repo = new EventRepository($this->db);
        $repo->add(new Event(100, Event::INFO, 'scale_out', '扩容 1 台', 'tokyo'));
        $warning = $repo->add(new Event(101, Event::WARNING, 'warmup_failed', '预热超时', 'tokyo', 'web-tokyo-a', ['timeout' => 900]));
        $repo->add(new Event(102, Event::CRITICAL, 'global_freeze', '全局冻结'));

        $pending = $repo->pendingNotifications();
        self::assertSame(['warmup_failed', 'global_freeze'], array_map(static function (Event $e) {
            return $e->type;
        }, $pending));
        self::assertSame(['timeout' => 900], $pending[0]->context);
        self::assertCount(1, $repo->pendingNotifications(Event::CRITICAL));

        $repo->markNotified([(int) $warning->id]);
        self::assertCount(1, $repo->pendingNotifications());

        $recent = $repo->recent(10, 'tokyo');
        self::assertSame(['warmup_failed', 'scale_out'], array_map(static function (Event $e) {
            return $e->type;
        }, $recent));
        self::assertCount(1, $repo->recent(10, null, 'web-tokyo-a'));
    }

    public function testAlertLogAndMeta(): void
    {
        $alerts = new AlertLog($this->db);
        self::assertTrue($alerts->shouldSend('tokyo:warmup_failed', 1000, 3600));
        $alerts->markSent('tokyo:warmup_failed', 1000);
        self::assertFalse($alerts->shouldSend('tokyo:warmup_failed', 4599, 3600));
        self::assertTrue($alerts->shouldSend('tokyo:warmup_failed', 4600, 3600));

        $meta = new Meta($this->db);
        self::assertNull($meta->get('frozen_since'));
        $meta->set('frozen_since', '1000');
        self::assertSame('1000', $meta->get('frozen_since'));
        $meta->delete('frozen_since');
        self::assertNull($meta->get('frozen_since'));
    }

    public function testTransactionRollsBackOnException(): void
    {
        $repo = new NodeRepository($this->db);
        try {
            $this->db->transaction(function () use ($repo): void {
                $repo->insert(new Node('web-tokyo-a', 'tokyo', 'aws', 'x', 0, 10, 1), 1);
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }
        self::assertNull($repo->find('web-tokyo-a'));

        $result = $this->db->transaction(function () use ($repo): string {
            $repo->insert(new Node('web-tokyo-b', 'tokyo', 'aws', 'x', 0, 10, 1), 1);
            return 'ok';
        });
        self::assertSame('ok', $result);
        self::assertNotNull($repo->find('web-tokyo-b'));
    }

    /**
     * @param Node[] $nodes
     * @return string[]
     */
    private static function ids(array $nodes): array
    {
        return array_map(static function (Node $node) {
            return $node->nodeId;
        }, $nodes);
    }
}
