<?php

namespace Wcore\Dnslb\Tests\Notify;

use PHPUnit\Framework\TestCase;
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
