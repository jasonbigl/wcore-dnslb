<?php

namespace Wcore\Dnslb\Tests\Aws;

use PHPUnit\Framework\TestCase;
use Wcore\Dnslb\Aws\Credentials;
use Wcore\Dnslb\Aws\SigV4Signer;
use Wcore\Dnslb\Http\Request;

/**
 * 使用 AWS 官方公布的签名示例验证。
 */
final class SigV4SignerTest extends TestCase
{
    private const TIME = 1440938160; // 2015-08-30T12:36:00Z

    private SigV4Signer $signer;

    protected function setUp(): void
    {
        $this->signer = new SigV4Signer(new Credentials('AKIDEXAMPLE', 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY'));
    }

    public function testGetVanilla(): void
    {
        $request = new Request('GET', 'https://example.amazonaws.com/');
        $signed = $this->signer->sign($request, 'service', 'us-east-1', self::TIME);

        self::assertSame('20150830T123600Z', $signed->header('X-Amz-Date'));
        self::assertSame('example.amazonaws.com', $signed->header('Host'));
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $signed->header('Authorization')
        );
    }

    public function testIamListUsersExample(): void
    {
        $request = new Request(
            'GET',
            'https://iam.amazonaws.com/?Action=ListUsers&Version=2010-05-08',
            ['Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8']
        );
        $signed = $this->signer->sign($request, 'iam', 'us-east-1', self::TIME);

        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/iam/aws4_request, '
            . 'SignedHeaders=content-type;host;x-amz-date, '
            . 'Signature=5d672d79c15b13162d9279b0855cfba6789a8edb4c82c400e06b5924a6f2b5d7',
            $signed->header('Authorization')
        );
    }

    public function testQueryParametersAreSortedRegardlessOfOrder(): void
    {
        $a = $this->signer->sign(new Request('GET', 'https://example.amazonaws.com/?b=2&a=1'), 'service', 'us-east-1', self::TIME);
        $b = $this->signer->sign(new Request('GET', 'https://example.amazonaws.com/?a=1&b=2'), 'service', 'us-east-1', self::TIME);

        self::assertSame($a->header('Authorization'), $b->header('Authorization'));
    }

    public function testResigningReplacesPreviousSignature(): void
    {
        $request = new Request('POST', 'https://example.amazonaws.com/', ['Content-Type' => 'application/json'], '{}');
        $first = $this->signer->sign($request, 'service', 'us-east-1', self::TIME);
        $second = $this->signer->sign($first, 'service', 'us-east-1', self::TIME + 60);
        $fresh = $this->signer->sign($request, 'service', 'us-east-1', self::TIME + 60);

        self::assertSame($fresh->headers, $second->headers);
        self::assertSame('20150830T123700Z', $second->header('X-Amz-Date'));
        self::assertNull($request->header('Authorization'), 'original request must not be modified');
    }
}
