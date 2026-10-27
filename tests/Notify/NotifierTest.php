<?php

namespace Wcore\Dnslb\Tests\Notify;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\TestApp;

final class NotifierTest extends TestCase
{
    private TestApp $t;

    protected function setUp(): void
    {
        $this->t = new TestApp(['notify' => [
            'region' => 'us-east-1',
            'from' => 'lb@example.com',
            'to' => ['ops@example.com', 'dev@example.com'],
            'throttle' => 3600,
        ]]);
    }

    public function testSendsOneEmailForPendingAlerts(): void
    {
        $events = $this->t->app->eventRecorder();
        $events->info('node_created', '创建实例', 'tokyo', 'web-tokyo-a');
        $events->warning('node_unhealthy', '节点 IPv4 连续 3 次健康检查失败，从 DNS 摘除：connect timeout', 'tokyo', 'web-tokyo-a');
        $events->critical('pool_down', '池内全部 2 个在线节点 IPv4 均不健康', 'tokyo');
        $events->info('pool_recovered', '池 IPv4 已恢复（中断 90 秒）', 'tokyo');

        $this->t->app->notifier()->flush();

        self::assertCount(1, $this->t->network->emails);
        $email = $this->t->network->emails[0];
        self::assertSame('lb@example.com', $email['FromEmailAddress']);
        self::assertSame(['ops@example.com', 'dev@example.com'], $email['Destination']['ToAddresses']);
        $subject = $email['Content']['Simple']['Subject']['Data'];
        self::assertSame('[dnslb:web] CRITICAL 节点 IPv4 连续 3 次健康检查失败，从 DNS 摘除：connect timeout 等 3 条', $subject);
        $body = $email['Content']['Simple']['Body']['Text']['Data'];
        self::assertStringContainsString('服务：web（app.example.com）', $body);
        self::assertMatchesRegularExpression('/\[WARNING\] \d{4}-\d\d-\d\d \d\d:\d\d:\d\d UTC  tokyo\/web-tokyo-a  节点 IPv4/', $body);
        self::assertStringContainsString('[CRITICAL]', $body);
        self::assertStringContainsString('[RECOVERY]', $body);
        self::assertStringNotContainsString('创建实例', $body, 'routine info events are not emailed');

        $this->t->app->notifier()->flush();
        self::assertCount(1, $this->t->network->emails, 'nothing left to send');
        self::assertSame([], $this->t->app->events()->pendingNotifications('info'));
    }

    public function testThrottlesRepeatedAlerts(): void
    {
        $events = $this->t->app->eventRecorder();
        $events->warning('node_unhealthy', 'first', 'tokyo', 'web-tokyo-a');
        $this->t->app->notifier()->flush();

        $events->warning('node_unhealthy', 'again', 'tokyo', 'web-tokyo-a');
        $events->warning('node_unhealthy', 'other node', 'tokyo', 'web-tokyo-b');
        $this->t->clock->advance(600);
        $this->t->app->notifier()->flush();

        self::assertCount(2, $this->t->network->emails);
        $body = $this->t->network->emails[1]['Content']['Simple']['Body']['Text']['Data'];
        self::assertStringContainsString('other node', $body);
        self::assertStringNotContainsString('again', $body);
        self::assertStringContainsString('另有 1 条同类告警在限频期内', $body);
    }

    public function testFailureAfterRecoveryIsSentDespiteThrottle(): void
    {
        $events = $this->t->app->eventRecorder();
        $notifier = $this->t->app->notifier();
        $events->critical('pool_down', '池 tokyo IPv4 全部不健康', 'tokyo');
        $notifier->flush();
        $this->t->clock->advance(300);
        $events->info('pool_recovered', '池 IPv4 已恢复', 'tokyo');
        $notifier->flush();
        $this->t->clock->advance(600);
        $events->critical('pool_down', '池 tokyo IPv4 再次全部不健康', 'tokyo');
        $notifier->flush();

        self::assertCount(3, $this->t->network->emails, 'the last email must not say "recovered" while the pool is down');
        self::assertStringContainsString('再次全部不健康', $this->t->network->emails[2]['Content']['Simple']['Body']['Text']['Data']);

        // 没有恢复通知时照常限频
        $this->t->clock->advance(60);
        $events->critical('pool_down', '池 tokyo IPv4 仍然全部不健康', 'tokyo');
        $notifier->flush();
        self::assertCount(3, $this->t->network->emails);
    }

    public function testEveryAlertGetsItsRecoveryWithinTheThrottleWindow(): void
    {
        $events = $this->t->app->eventRecorder();
        $notifier = $this->t->app->notifier();
        // 实测：一小时内第二次恢复被自身限频丢弃，告警键没有清除，第三次故障也不发
        foreach (['held', 'released', 'held', 'released', 'held', 'released'] as $i => $step) {
            if ($step === 'held') {
                $events->critical('dns_empty_held', "保留记录 {$i}");
            } else {
                $events->info('dns_empty_released', "恢复同步 {$i}");
            }
            $notifier->flush();
            $this->t->clock->advance(180);
        }

        self::assertCount(6, $this->t->network->emails);
        foreach ($this->t->network->emails as $i => $email) {
            self::assertStringContainsString((string) $i, $email['Content']['Simple']['Body']['Text']['Data']);
        }
        self::assertNull($this->t->app->alertLog()->lastSentAt('dns_empty_held||'), 'recovered: the next incident alerts at once');
    }

    public function testRecoveryIsOnlySentForAlertsThatWereSent(): void
    {
        $events = $this->t->app->eventRecorder();
        $notifier = $this->t->app->notifier();
        $events->info('pool_recovered', '池 tokyo IPv4 已恢复', 'tokyo');
        $events->info('canary_recovered', '探测机网络已恢复');
        $notifier->flush();
        self::assertSame([], $this->t->network->emails, 'no alert was sent, so there is nothing to recover');

        $events->critical('pool_down', '池 tokyo IPv4 全部不健康', 'tokyo');
        $notifier->flush();
        $this->t->clock->advance(60);
        $events->info('pool_recovered', '池 frankfurt IPv4 已恢复', 'frankfurt');
        $events->info('pool_recovered', '池 tokyo IPv4 已恢复', 'tokyo');
        $events->info('pool_recovered', '池 tokyo IPv4 再次恢复', 'tokyo');
        $notifier->flush();

        self::assertCount(2, $this->t->network->emails);
        $body = $this->t->network->emails[1]['Content']['Simple']['Body']['Text']['Data'];
        self::assertStringContainsString('池 tokyo IPv4 已恢复', $body);
        self::assertStringNotContainsString('frankfurt', $body, 'paired per pool');
        self::assertStringNotContainsString('再次恢复', $body, 'one recovery per alert');
        self::assertStringNotContainsString('限频期内', $body);
    }

    public function testAlertsAndRecoveriesInOneEmailArePairedInOrder(): void
    {
        $events = $this->t->app->eventRecorder();
        $events->critical('global_freeze', '冻结 1');
        $events->info('global_freeze_lifted', '解除 1');
        $events->critical('global_freeze', '冻结 2');
        $events->critical('global_freeze', '冻结 2 仍在');
        $this->t->app->notifier()->flush();

        $body = $this->t->network->emails[0]['Content']['Simple']['Body']['Text']['Data'];
        foreach (['冻结 1', '解除 1', '冻结 2', '冻结 2 仍在'] as $message) {
            self::assertStringContainsString($message, $body);
        }
        self::assertSame($this->t->clock->now(), $this->t->app->alertLog()->lastSentAt('global_freeze||'), 'still frozen');

        $this->t->clock->advance(60);
        $events->info('global_freeze_lifted', '解除 2');
        $this->t->app->notifier()->flush();
        self::assertCount(2, $this->t->network->emails);
        self::assertNull($this->t->app->alertLog()->lastSentAt('global_freeze||'));
    }

    public function testNodeFailureAndRecoveryAreBothEmailedEveryTime(): void
    {
        foreach (['a' => '10.0.0.1', 'b' => '10.0.0.2', 'c' => '10.0.0.3'] as $suffix => $ip) {
            $this->t->addNode("web-tokyo-{$suffix}", 'tokyo', Node::IN_SERVICE, $ip);
        }
        $health = $this->t->app->healthChecker();
        $notifier = $this->t->app->notifier();
        // 同一节点在限频期内故障、恢复两次
        for ($cycle = 0; $cycle < 2; $cycle++) {
            $this->t->network->status['10.0.0.1'] = 503;
            for ($i = 0; $i < 2; $i++) {
                $health->runRound();
                $this->t->clock->advance(10);
            }
            $notifier->flush();
            unset($this->t->network->status['10.0.0.1']);
            for ($i = 0; $i < 2; $i++) {
                $health->runRound();
                $this->t->clock->advance(10);
            }
            $notifier->flush();
        }

        $subjects = array_map(static function (array $email): string {
            return $email['Content']['Simple']['Subject']['Data'];
        }, $this->t->network->emails);
        self::assertCount(4, $subjects);
        self::assertStringStartsWith('[dnslb:web] WARNING 节点 IPv4 连续 2 次健康检查失败，从 DNS 摘除', $subjects[0]);
        self::assertSame('[dnslb:web] RECOVERY 节点 IPv4 健康检查恢复，重新加入 DNS', $subjects[1]);
        self::assertSame([$subjects[0], $subjects[1]], [$subjects[2], $subjects[3]]);
        self::assertNull($this->t->app->alertLog()->lastSentAt('node_unhealthy|tokyo|web-tokyo-a'));
    }

    public function testFailedSendIsRetriedNextTime(): void
    {
        $this->t->app->eventRecorder()->critical('global_freeze', '冻结');
        $this->t->network->sesFails = true;

        $this->t->app->notifier()->flush();
        self::assertSame([], $this->t->network->emails);
        self::assertNotEmpty($this->t->logger->matching('Failed to send notification email'));

        $this->t->network->sesFails = false;
        $this->t->app->notifier()->flush();
        self::assertCount(1, $this->t->network->emails);
    }

    public function testWithoutNotifyConfigNothingIsSent(): void
    {
        $t = new TestApp();
        $t->app->eventRecorder()->critical('global_freeze', '冻结');

        self::assertNull($t->app->notifier());
        $t->app->tickRunner()->run(false);

        self::assertSame([], $t->network->emails);
    }
}
