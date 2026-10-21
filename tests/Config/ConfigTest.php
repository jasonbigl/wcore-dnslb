<?php

namespace Wcore\Dnslb\Tests\Config;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Config\Config;
use Wcore\Dnslb\Config\ConfigException;
use Wcore\Dnslb\Config\DnsConfig;

final class ConfigTest extends TestCase
{
    private const EXAMPLE = __DIR__ . '/../../examples/config.php';

    public function testExampleConfigLoads(): void
    {
        $config = Config::load(self::EXAMPLE);
        $examples = realpath(__DIR__ . '/../../examples');

        self::assertSame('web', $config->service);
        self::assertSame('/var/lib/dnslb/web.sqlite', $config->statePath);
        self::assertSame("{$examples}/cloud-init.yaml", $config->userData->template);
        self::assertSame('Z0123456789ABCDEFGHIJ', $config->dns->zoneId);
        self::assertSame('tokyo.pool.app.example.com', $config->dns->poolRecordName('tokyo'));
        self::assertSame(DnsConfig::ROUTING_MULTIVALUE, $config->dns->poolRouting);
        self::assertTrue($config->dns->ipv6);
        self::assertSame('app.example.com', $config->healthCheck->host);
        self::assertSame(['tokyo', 'frankfurt'], array_keys($config->pools));

        $tokyo = $config->pools['tokyo'];
        self::assertCount(2, $tokyo->launch);
        self::assertSame('linode', $tokyo->launch[0]->driver);
        self::assertSame('g6-dedicated-2', $tokyo->launch[0]->options['type']);
        self::assertSame(10, $tokyo->launch[0]->weight);
        self::assertSame('ec2', $tokyo->launch[1]->driver);
        self::assertSame(1, $tokyo->launch[1]->index);
        self::assertSame(['sg-0123456789abcdef0'], $tokyo->launch[1]->options['security_group_ids']);
        self::assertSame(70.0, $tokyo->scaling->metrics['cpu']->scaleOutAbove);

        // 法兰克福覆盖了扩容阈值，其他项继承全局
        $frankfurt = $config->pools['frankfurt']->scaling;
        self::assertSame(80.0, $frankfurt->metrics['cpu']->scaleOutAbove);
        self::assertSame(30.0, $frankfurt->metrics['cpu']->scaleInBelow);
        self::assertSame('grafana', $frankfurt->metrics['cpu']->source);
        self::assertSame(900, $frankfurt->cooldownIn);
    }

    public function testCloudflareExampleConfigLoads(): void
    {
        $config = Config::load(__DIR__ . '/../../examples/config-cloudflare.php');

        self::assertTrue($config->dns->isCloudflare());
        self::assertTrue($config->dns->proxied);
        self::assertSame('api.example.com', $config->healthCheck->host);
        self::assertSame(['dallas', 'newark'], array_keys($config->pools));
        self::assertNull($config->pools['dallas']->latencyRegion);
    }

    public function testMinimalConfigUsesDefaults(): void
    {
        $config = Config::fromArray($this->minimal(), $this->templateDir());

        self::assertSame('{pool}.pool.app.example.com', $config->dns->poolDomain);
        self::assertSame(60, $config->dns->ttl);
        self::assertFalse($config->dns->ipv6);
        self::assertSame('https', $config->healthCheck->scheme);
        self::assertSame(443, $config->healthCheck->port);
        self::assertSame('/health', $config->healthCheck->path);
        self::assertSame(3.0, $config->healthCheck->connectTimeout);
        self::assertCount(2, $config->healthCheck->canaryUrls);
        self::assertSame(900, $config->warmupTimeout);
        self::assertSame(300, $config->drain->min);
        self::assertTrue($config->autoheal->enabled);
        self::assertSame([], $config->pools['tokyo']->scaling->metrics);
        self::assertSame(1, $config->pools['tokyo']->min);
        self::assertNull($config->notify);
        self::assertNull($config->heartbeat);
        self::assertSame($this->templateDir() . '/state/web.sqlite', $config->statePath);
    }

    public function testWildcardDomain(): void
    {
        $data = $this->minimal();
        $data['dns']['name'] = '*.Example.net';
        $data['health_check'] = ['host' => 'health.example.net'];

        $config = Config::fromArray($data, $this->templateDir());

        self::assertTrue($config->dns->wildcard);
        self::assertSame('*.example.net', $config->dns->name);
        self::assertSame('tokyo.dnslb-pool.example.net', $config->dns->poolRecordName('tokyo'));
        self::assertSame('health.example.net', $config->healthCheck->host);
        self::assertFalse(Config::fromArray($this->minimal(), $this->templateDir())->dns->wildcard);
    }

    public function testWildcardDomainRequiresConcreteHealthHost(): void
    {
        $data = $this->minimal();
        $data['dns']['name'] = '*.example.net';
        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('health_check.host: is required when dns.name is a wildcard', $e->getMessage());
        }

        $data['health_check'] = ['host' => '*.example.net'];
        $this->expectExceptionMessage('health_check.host: must be a concrete domain name');
        Config::fromArray($data, $this->templateDir());
    }

    public function testWildcardOnlyAllowedAsLeftmostLabel(): void
    {
        $data = $this->minimal();
        $data['dns']['name'] = 'app.*.example.net';
        $data['health_check'] = ['host' => 'health.example.net'];

        $this->expectExceptionMessage('dns.name: invalid domain name "app.*.example.net"');
        Config::fromArray($data, $this->templateDir());
    }

    public function testCredentialsWithControlCharactersAreRejectedWithoutEchoingThem(): void
    {
        $data = $this->minimal();
        $data['aws']['key'] = "AKIAEXAMPLE\r";
        $data['providers']['linode']['token'] = 'tok en';

        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            self::assertStringContainsString('aws.key: contains whitespace or control characters', $e->getMessage());
            self::assertStringContainsString('providers.linode.token: contains whitespace or control characters', $e->getMessage());
            self::assertStringNotContainsString('AKIAEXAMPLE', $e->getMessage());
            self::assertStringNotContainsString('tok en', $e->getMessage());
        }
    }

    public function testZoneIdPrefixIsStripped(): void
    {
        $data = $this->minimal();
        $data['dns']['zone_id'] = '/hostedzone/ZABC';
        self::assertSame('ZABC', Config::fromArray($data, $this->templateDir())->dns->zoneId);
    }

    public function testCollectsAllErrors(): void
    {
        $data = $this->minimal();
        $data['service'] = 'Web_1';
        $data['dsn'] = [];
        $data['dns']['pool_domain'] = 'pool.app.example.com';
        $data['health_check'] = ['interval' => 1, 'expect_status' => [300, 200]];
        $data['scaling'] = ['metrics' => ['cpu' => [
            'source' => 'missing',
            'query' => 'x',
            'scale_out_above' => 30,
            'scale_in_below' => 70,
        ]]];
        $data['pools']['osaka'] = [
            'latency_region' => 'ap-northeast-1',
            'min' => 5,
            'max' => 2,
            'launch' => [['provider' => 'nope', 'region' => 'x']],
        ];
        $data['pools']['tokyo']['launch'][0]['typo'] = 1;

        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $errors = implode("\n", $e->getErrors());
            self::assertStringContainsString('service: invalid value "Web_1"', $errors);
            self::assertStringContainsString('dsn: unknown option', $errors);
            self::assertStringContainsString('dns.pool_domain: must start with "{pool}."', $errors);
            self::assertStringContainsString('health_check.interval: must be between 2 and 55', $errors);
            self::assertStringContainsString('health_check.expect_status', $errors);
            self::assertStringContainsString('scaling.metrics.cpu.scale_in_below: must be lower than scale_out_above', $errors);
            self::assertStringContainsString('scaling.metrics.cpu.source: unknown metric source "missing"', $errors);
            self::assertStringContainsString('pools.osaka.max: must be greater than or equal to min', $errors);
            self::assertStringContainsString('pools.osaka.launch.0.provider: unknown provider "nope"', $errors);
            self::assertStringContainsString('pools.osaka.latency_region: "ap-northeast-1" is already used by pool "tokyo"', $errors);
            self::assertStringContainsString('pools.tokyo.launch.0.typo: unknown option', $errors);
        }
    }

    public function testMissingSectionsAreReported(): void
    {
        try {
            Config::fromArray(['service' => 'web', 'state' => 'x.sqlite'], $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $errors = $e->getErrors();
            self::assertContains('aws: is required (used by Route 53 DNS)', $errors);
            self::assertContains('providers: is required', $errors);
            self::assertContains('dns.zone_id: is required', $errors);
            self::assertContains('user_data.template: is required', $errors);
            self::assertContains('pools: is required', $errors);
        }
    }

    public function testCloudflareDnsNeedsNoAwsOrLatencyRegions(): void
    {
        $data = $this->cloudflare();

        $config = Config::fromArray($data, $this->templateDir());

        self::assertTrue($config->dns->isCloudflare());
        self::assertSame('0123456789abcdef0123456789abcdef', $config->dns->zoneId);
        self::assertTrue($config->dns->proxied);
        self::assertSame(60, $config->dns->ttl);
        self::assertSame('CF-TOKEN', $config->cloudflareToken);
        self::assertNull($config->pools['tokyo']->latencyRegion);
        self::assertSame('', $config->aws->accessKeyId);

        $data['dns'] += ['proxied' => false, 'ttl' => 300];
        $dnsOnly = Config::fromArray($data, $this->templateDir())->dns;
        self::assertFalse($dnsOnly->proxied);
        self::assertSame(300, $dnsOnly->ttl);
    }

    public function testCloudflareDnsValidation(): void
    {
        $data = $this->cloudflare();
        unset($data['cloudflare']);
        $data['dns'] += ['zone_id' => 'Z1', 'pool_routing' => 'weighted', 'ttl' => 10];
        $data['dns']['zone_id'] = 'Z1';
        $data['pools']['tokyo']['latency_region'] = 'ap-northeast-1';
        $data['providers']['aws'] = ['driver' => 'ec2'];
        $data['notify'] = ['region' => 'us-east-1', 'from' => 'lb@example.com', 'to' => ['ops@example.com']];

        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $errors = $e->getErrors();
            self::assertContains('dns.zone_id: invalid value "Z1"', $errors);
            self::assertContains('dns.pool_routing: is not used with provider cloudflare', $errors);
            self::assertContains('dns.ttl: must be between 30 and 86400', $errors);
            self::assertContains('pools.tokyo.latency_region: is not used with dns.provider cloudflare (traffic is spread across all pools)', $errors);
            self::assertContains('cloudflare: is required', $errors);
            self::assertContains('aws: is required (used by EC2 provider aws, SES notifications)', $errors);
        }
    }

    public function testProxiedIsOnlyForCloudflare(): void
    {
        $data = $this->minimal();
        $data['dns']['proxied'] = true;

        $this->expectExceptionMessage('dns.proxied: is not used with provider route53');
        Config::fromArray($data, $this->templateDir());
    }

    public function testPoolScalingOverrideCanDisableMetric(): void
    {
        $data = $this->minimal();
        $data['metric_sources'] = ['prom' => ['url' => 'https://prom.example.com']];
        $data['scaling'] = ['metrics' => [
            'cpu' => ['source' => 'prom', 'query' => 'cpu', 'scale_out_above' => 70, 'scale_in_below' => 30],
            'bw' => ['source' => 'prom', 'query' => 'bw', 'scale_out_above' => 800, 'scale_in_below' => 100, 'aggregate' => 'max'],
        ]];
        $data['pools']['tokyo']['scaling'] = ['step' => 2, 'metrics' => ['bw' => null]];

        $scaling = Config::fromArray($data, $this->templateDir())->pools['tokyo']->scaling;

        self::assertSame(2, $scaling->step);
        self::assertSame(['cpu'], array_keys($scaling->metrics));
    }

    public function testLinodeLaunchSpecValidation(): void
    {
        $data = $this->minimal();
        $data['pools']['tokyo']['launch'][0] = ['provider' => 'linode', 'region' => 'ap-northeast', 'firewall_id' => '12'];

        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $errors = $e->getErrors();
            self::assertContains('pools.tokyo.launch.0.type: is required', $errors);
            self::assertContains('pools.tokyo.launch.0.image: is required', $errors);
            self::assertContains('pools.tokyo.launch.0.firewall_id: must be an integer', $errors);
        }
    }

    public function testUnknownTemplatePlaceholderIsReported(): void
    {
        $data = $this->minimal();
        $data['user_data']['template'] = realpath(__DIR__ . '/../../examples/cloud-init.yaml');
        $data['user_data']['vars'] = ['cert_center_url' => 'https://c', 'grafana_username' => 'u', 'node_id' => 'x'];

        try {
            Config::fromArray($data, $this->templateDir());
            self::fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $errors = implode("\n", $e->getErrors());
            self::assertStringContainsString('user_data.vars.node_id: is a built-in placeholder', $errors);
            self::assertStringContainsString('unknown placeholder {{grafana_remote_write_url}}', $errors);
            self::assertStringContainsString('unknown placeholder {{grafana_write_token}}', $errors);
            self::assertStringNotContainsString('{{cert_center_url}}', $errors);
        }
    }

    public function testMissingTemplateIsReported(): void
    {
        $data = $this->minimal();
        $data['user_data']['template'] = 'does-not-exist.yaml';

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('user_data.template: file not readable');
        Config::fromArray($data, $this->templateDir());
    }

    private function minimal(): array
    {
        return [
            'service' => 'web',
            'state' => 'state/web.sqlite',
            'aws' => ['key' => 'AKID', 'secret' => 'SECRET'],
            'providers' => [
                'aws' => ['driver' => 'ec2'],
                'linode' => ['driver' => 'linode', 'token' => 't'],
            ],
            'dns' => ['zone_id' => 'Z1', 'name' => 'App.Example.com.'],
            'user_data' => ['template' => 'cloud-init.yaml'],
            'pools' => [
                'tokyo' => [
                    'latency_region' => 'ap-northeast-1',
                    'max' => 3,
                    'launch' => [[
                        'provider' => 'aws',
                        'region' => 'ap-northeast-1',
                        'instance_type' => 't3.micro',
                        'ami' => 'ami-0abc',
                        'subnet_id' => 'subnet-0abc',
                    ]],
                ],
            ],
        ];
    }

    private function cloudflare(): array
    {
        return [
            'service' => 'web',
            'state' => 'state/web.sqlite',
            'cloudflare' => ['api_token' => 'CF-TOKEN'],
            'providers' => ['linode' => ['driver' => 'linode', 'token' => 't']],
            'dns' => ['provider' => 'cloudflare', 'zone_id' => '0123456789abcdef0123456789abcdef', 'name' => 'app.example.com'],
            'user_data' => ['template' => 'cloud-init.yaml'],
            'pools' => [
                'tokyo' => [
                    'max' => 3,
                    'launch' => [['provider' => 'linode', 'region' => 'ap-northeast', 'type' => 'g6-standard-2', 'image' => 'linode/debian12']],
                ],
            ],
        ];
    }

    private function templateDir(): string
    {
        return (string) realpath(__DIR__ . '/../fixtures');
    }
}
