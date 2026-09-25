<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Azure;

use CORS\Bundle\CdnBundle\Provider\Azure\FrontDoorPurgeClient;
use CORS\Bundle\CdnBundle\Purge\AssetThumbnailDirectoryResolverInterface;
use CORS\Bundle\CdnBundle\Purge\TagToPathMapper;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FrontDoorPurgeClientTest extends TestCase
{
    private const RESOURCE = '/subscriptions/s/resourceGroups/rg/providers/Microsoft.Cdn/profiles/p/afdEndpoints/e';

    private const TOKEN_URL = 'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token';

    private const PURGE_URL = 'https://management.azure.com' . self::RESOURCE . '/purge?api-version=2024-02-01';

    private ClientInterface&MockObject $http;

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    private array $calls = [];

    private FrontDoorPurgeClient $client;

    protected function setUp(): void
    {
        $this->http = $this->createMock(ClientInterface::class);
        $this->http->method('request')->willReturnCallback(function (string $m, string $u, array $o) {
            $this->calls[] = [$m, $u, $o];

            return $u === self::TOKEN_URL
                ? new Response(200, [], '{"access_token":"eyJ.token","expires_in":3599}')
                : new Response(202);
        });
        $resolver = new class implements AssetThumbnailDirectoryResolverInterface {
            public function resolve(int $assetId): ?string
            {
                return '/img/' . $assetId . '/';
            }
        };
        $this->client = new FrontDoorPurgeClient(
            $this->http,
            new NullLogger(),
            new TagToPathMapper($resolver, new NullLogger()),
            self::RESOURCE,
            'tenant-1',
            'client-1',
            'secret-1',
            '2024-02-01',
        );
    }

    public function testFetchesTokenThenPurgesMappedPaths(): void
    {
        $this->client->purgeByTags(['asset-42']);

        self::assertCount(2, $this->calls);
        [$m, $u, $o] = $this->calls[0];
        self::assertSame([self::TOKEN_URL, 'client_credentials', 'https://management.azure.com/.default'], [$u, $o['form_params']['grant_type'], $o['form_params']['scope']]);
        [$m, $u, $o] = $this->calls[1];
        self::assertSame(['POST', self::PURGE_URL], [$m, $u]);
        self::assertSame('Bearer eyJ.token', $o['headers']['Authorization']);
        self::assertSame(['contentPaths' => ['/img/42/*']], $o['json']);
    }

    public function testTokenIsCachedAcrossCalls(): void
    {
        $this->client->purgeByTag('asset-1');
        $this->client->purgeByTag('asset-2');

        $tokenCalls = array_filter($this->calls, static fn (array $c) => $c[1] === self::TOKEN_URL);
        self::assertCount(1, $tokenCalls);
    }

    public function testPurgeByUrlSendsPathAndDomain(): void
    {
        $this->client->purgeByUrl('https://cdn.example.com/var/assets/a%20b.jpg?v=1');

        $o = $this->calls[1][2];
        self::assertSame(['contentPaths' => ['/var/assets/a%20b.jpg'], 'domains' => ['cdn.example.com']], $o['json']);
    }

    public function testUnmappableTagsOnlySendNothing(): void
    {
        $this->client->purgeByTags(['thumb-teaser']);

        self::assertSame([], $this->calls);
    }
}
