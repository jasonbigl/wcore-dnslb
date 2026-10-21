<?php

namespace Wcore\Dnslb\Tests;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Config\ConfigException;
use Wcore\Dnslb\Dnslb;
use Wcore\Dnslb\State\Database;
use Wcore\Dnslb\State\Node;
use Wcore\Dnslb\Tests\Support\FakeClock;
use Wcore\Dnslb\Tests\Support\FakeNetwork;
use Wcore\Dnslb\Tests\Support\FakeProvider;
use Wcore\Dnslb\Tests\Support\FakeTransport;
use Wcore\Dnslb\Tests\Support\TestApp;

final class DnslbTest extends TestCase
{
    private FakeNetwork $network;
    private FakeProvider $aws;
    private Database $db;
    /** @var string[] */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->network = new FakeNetwork();
        $this->aws = new FakeProvider('aws');
        $this->db = Database::open(':memory:');
    }

    public function testFactories(): void
    {
        $examples = (string) realpath(__DIR__ . '/../examples');
        $fromFile = Dnslb::fromConfigFile("{$examples}/config.php");
        self::assertSame('web', $fromFile->config()->service);
        self::assertSame("{$examples}/cloud-init.yaml", $fromFile->config()->userData->template);

        $data = require "{$examples}/config.php";
        $fromArray = Dnslb::fromConfigArray($data, $examples);
        self::assertSame(['tokyo', 'frankfurt'], array_keys($fromArray->config()->pools));

        $this->expectException(ConfigException::class);
        Dnslb::fromConfigArray(['service' => 'web'], $examples);
    }

    public function testTickAndHealthWithPsrStyleLogger(): void
    {
        $logger = new class ($this->logged) {
            /** @var string[] */
            private array $lines;

            public function __construct(array &$lines)
            {
                $this->lines = &$lines;
            }

            public function __call(string $level, array $args): void
            {
                $this->lines[] = "{$level} {$args[0]}";
            }
        };
        $lb = $this->lb(['logger' => $logger]);

        self::assertTrue($lb->tick());
        self::assertSame(1, $lb->health(10, true));
        $lb->app()->lock('tick')->release();

        self::assertCount(2, $this->aws->callsMatching('create'), 'min=1 for both pools');
        self::assertNotEmpty(array_filter($this->logged, static function (string $line): bool {
            return strpos($line, 'info [dnslb:tick] ') === 0;
        }));
        self::assertNotEmpty(array_filter($this->logged, static function (string $line): bool {
            return strpos($line, '[dnslb:health] Health round') !== false;
        }));
    }

    public function testDryRunTickChangesNothing(): void
    {
        $lb = $this->lb();

        self::assertTrue($lb->tick(true));

        self::assertSame([], $this->aws->calls);
        self::assertSame([], $lb->app()->nodes()->live(), 'state database untouched');
    }

    /**
     * 配置中的 dry_run 与方法参数等效：默认参数调用也不能写 DNS 或发心跳。
     *
     * @dataProvider dnsProviders
     */
    public function testConfigDryRunAppliesToDefaultCalls(bool $cloudflare): void
    {
        $t = new TestApp(['dry_run' => true, 'dns' => ['ipv6' => false], 'heartbeat' => ['url' => 'https://heartbeat.test/{{cmd}}']], $cloudflare);
        $t->addNode('web-tokyo-aaaaaa', 'tokyo', Node::IN_SERVICE, '10.0.0.1');
        $lb = $t->lb(['aws' => $this->aws]);

        self::assertTrue($lb->tick());
        self::assertSame(1, $lb->health(0, true));
        self::assertTrue($lb->takeover()->dryRun);

        self::assertSame([], $t->network->route53->batches);
        self::assertSame([], $t->network->cloudflare->batches);
        self::assertSame([], $t->network->heartbeats);
    }

    public function dnsProviders(): array
    {
        return ['route53' => [false], 'cloudflare' => [true]];
    }

    public function testRejectsLoggerWithoutPsrMethods(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->lb(['logger' => new \stdClass()])->tick();
    }

    private function lb(array $options = []): Dnslb
    {
        $config = TestApp::config(['dns' => ['ipv6' => false]]);
        return new Dnslb($config, $options + [
            'transport' => (new FakeTransport())->handle($this->network),
            'clock' => new FakeClock(1700000000),
            'database' => $this->db,
            'providers' => ['aws' => $this->aws],
            'logger' => new \Wcore\Dnslb\Support\NullLogger(),
        ]);
    }
}
