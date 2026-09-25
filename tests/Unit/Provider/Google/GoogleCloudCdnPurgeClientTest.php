<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Google;

use CORS\Bundle\CdnBundle\Provider\Google\GoogleCloudCdnPurgeClient;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class GoogleCloudCdnPurgeClientTest extends TestCase
{
    private const ENDPOINT = 'https://compute.googleapis.com/compute/v1/projects/my-proj/global/urlMaps/my-map/invalidateCache';

    private ClientInterface&MockObject $http;

    private FetchAuthTokenInterface&MockObject $credentials;

    private GoogleCloudCdnPurgeClient $client;

    protected function setUp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->credentials = $this->createMock(FetchAuthTokenInterface::class);
        $this->credentials->method('fetchAuthToken')->willReturn(['access_token' => 'ya29.token']);
        $this->client = new GoogleCloudCdnPurgeClient(
            $this->http,
            new NullLogger(),
            $this->credentials,
            'my-proj',
            'my-map',
            'https://compute.googleapis.com/compute/v1',
            3,
        );
    }

    public function testPurgeByTagsSendsCacheTagsWithBearer(): void
    {
        $this->http->expects($this->once())
            ->method('request')
            ->with('POST', self::ENDPOINT, $this->callback(function (array $o): bool {
                self::assertSame('Bearer ya29.token', $o['headers']['Authorization']);
                self::assertSame(['cacheTags' => ['asset-1', 'thumb-x']], $o['json']);

                return true;
            }))
            ->willReturn(new Response(200, [], '{"kind":"compute#operation","status":"RUNNING"}'));

        $this->client->purgeByTags(['asset-1', 'thumb-x']);
    }

    public function testPurgeByTagsChunks(): void
    {
        $sizes = [];
        $this->http->expects($this->exactly(3))->method('request')
            ->willReturnCallback(function (string $m, string $u, array $o) use (&$sizes) {
                $sizes[] = count($o['json']['cacheTags']);

                return new Response(200);
            });

        $this->client->purgeByTags(array_map(static fn (int $i) => 'asset-' . $i, range(1, 7)));

        self::assertSame([3, 3, 1], $sizes);
    }

    public function testPurgeByUrlSendsPathAndHost(): void
    {
        $this->http->expects($this->once())
            ->method('request')
            ->with('POST', self::ENDPOINT, $this->callback(
                fn (array $o) => $o['json'] === ['path' => '/var/assets/a%20b.jpg?v=3', 'host' => 'cdn.example.com']
            ))
            ->willReturn(new Response(200));

        $this->client->purgeByUrl('https://cdn.example.com/var/assets/a%20b.jpg?v=3');
    }

    public function testMissingTokenThrows(): void
    {
        $creds = $this->createMock(FetchAuthTokenInterface::class);
        $creds->method('fetchAuthToken')->willReturn([]);
        $client = new GoogleCloudCdnPurgeClient($this->http, new NullLogger(), $creds, 'p', 'm', 'https://x');
        $this->http->expects($this->never())->method('request');
        $this->expectException(\RuntimeException::class);

        $client->purgeByTag('asset-1');
    }

    public function testNon2xxThrows(): void
    {
        $this->http->method('request')->willReturn(new Response(403, [], '{"error":{"message":"forbidden"}}'));
        $this->expectExceptionMessage('HTTP 403');

        $this->client->purgeByTag('asset-1');
    }
}
