<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Cloudflare;

use CORS\Bundle\CdnBundle\Provider\Cloudflare\CloudflarePurgeClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CloudflarePurgeClientTest extends TestCase
{
    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache';

    private ClientInterface&MockObject $http;

    private CloudflarePurgeClient $client;

    protected function setUp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->client = new CloudflarePurgeClient(
            $this->http,
            new NullLogger(),
            'zone123',
            'secret-token',
            'https://api.cloudflare.com/client/v4/',
        );
    }

    private function ok(): Response
    {
        return new Response(200, [], '{"success":true,"result":{"id":"x"},"errors":[],"messages":[]}');
    }

    public function testPurgeByTagsPostsTagsWithBearerToken(): void
    {
        $this->http->expects($this->once())
            ->method('request')
            ->with('POST', self::ENDPOINT, $this->callback(function (array $o): bool {
                self::assertSame('Bearer secret-token', $o['headers']['Authorization']);
                self::assertSame(['tags' => ['asset-42', 'thumb-teaser']], $o['json']);
                self::assertFalse($o['http_errors']);

                return true;
            }))
            ->willReturn($this->ok());

        $this->client->purgeByTags(['asset-42', 'thumb-teaser', 'asset-42']);
    }

    public function testPurgeByTagDelegatesToPurgeByTags(): void
    {
        $this->http->expects($this->once())
            ->method('request')
            ->with('POST', self::ENDPOINT, $this->callback(fn (array $o) => $o['json'] === ['tags' => ['asset-1']]))
            ->willReturn($this->ok());

        $this->client->purgeByTag('asset-1');
    }

    public function testPurgeByTagsChunksAtProviderLimit(): void
    {
        $tags = array_map(static fn (int $i) => 'asset-' . $i, range(1, 250));
        $sizes = [];

        $this->http->expects($this->exactly(3))
            ->method('request')
            ->willReturnCallback(function (string $m, string $u, array $o) use (&$sizes) {
                $sizes[] = count($o['json']['tags']);

                return $this->ok();
            });

        $this->client->purgeByTags($tags);

        self::assertSame([100, 100, 50], $sizes);
    }

    public function testPurgeByTagsWithEmptyListDoesNothing(): void
    {
        $this->http->expects($this->never())->method('request');

        $this->client->purgeByTags([]);
    }

    public function testPurgeByTagsRejectsWhitespace(): void
    {
        $this->http->expects($this->never())->method('request');
        $this->expectException(\InvalidArgumentException::class);

        $this->client->purgeByTags(['asset 1']);
    }

    public function testPurgeByUrlPostsFiles(): void
    {
        $this->http->expects($this->once())
            ->method('request')
            ->with('POST', self::ENDPOINT, $this->callback(
                fn (array $o) => $o['json'] === ['files' => ['https://cdn.example.com/var/assets/a%20b.jpg']]
            ))
            ->willReturn($this->ok());

        $this->client->purgeByUrl('https://cdn.example.com/var/assets/a%20b.jpg');
    }

    public function testNon2xxThrowsSoMessengerRetries(): void
    {
        $this->http->method('request')->willReturn(new Response(429, [], '{"success":false,"errors":[]}'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 429');

        $this->client->purgeByUrl('https://cdn.example.com/x.jpg');
    }

    public function testSuccessFalseWith200Throws(): void
    {
        $this->http->method('request')->willReturn(new Response(
            200,
            [],
            '{"success":false,"errors":[{"code":10000,"message":"Authentication error"}]}',
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Authentication error (10000)');

        $this->client->purgeByTag('asset-1');
    }

    public function testTransportExceptionIsRethrown(): void
    {
        $this->http->method('request')->willThrowException(new \RuntimeException('connection refused'));
        $this->expectExceptionMessage('connection refused');

        $this->client->purgeByTag('asset-1');
    }
}
