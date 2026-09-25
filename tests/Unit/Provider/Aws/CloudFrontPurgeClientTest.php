<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Aws;

use Aws\CloudFront\CloudFrontClient;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use CORS\Bundle\CdnBundle\Provider\Aws\CloudFrontPurgeClient;
use CORS\Bundle\CdnBundle\Purge\AssetThumbnailDirectoryResolverInterface;
use CORS\Bundle\CdnBundle\Purge\TagToPathMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CloudFrontPurgeClientTest extends TestCase
{
    private MockHandler $handler;

    /** @var list<array<string, mixed>> */
    private array $commands = [];

    private function client(bool $tagInvalidation = true): CloudFrontPurgeClient
    {
        $this->handler = new MockHandler();
        $this->handler->append(function (CommandInterface $cmd) {
            $this->commands[] = $cmd->toArray();

            return new Result(['Invalidation' => ['Id' => 'I1', 'Status' => 'InProgress']]);
        });
        $sdk = new CloudFrontClient([
            'region' => 'us-east-1',
            'version' => '2020-05-31',
            'credentials' => false,
            'handler' => $this->handler,
        ]);
        $resolver = new class implements AssetThumbnailDirectoryResolverInterface {
            public function resolve(int $assetId): ?string
            {
                return '/img/' . $assetId . '/';
            }
        };

        return new CloudFrontPurgeClient($sdk, new NullLogger(), new TagToPathMapper($resolver, new NullLogger()), 'E123', $tagInvalidation);
    }

    public function testTagsAreSentAsHashItems(): void
    {
        $this->client()->purgeByTags(['asset-42', 'thumb-teaser', 'asset-42']);

        self::assertCount(1, $this->commands);
        $c = $this->commands[0];
        self::assertSame('E123', $c['DistributionId']);
        self::assertSame(2, $c['InvalidationBatch']['Paths']['Quantity']);
        self::assertSame(['#asset-42', '#thumb-teaser'], $c['InvalidationBatch']['Paths']['Items']);
        self::assertStringStartsWith('pimcore-cdn-', $c['InvalidationBatch']['CallerReference']);
    }

    public function testWithoutTagInvalidationTagsAreMappedToPaths(): void
    {
        $this->client(false)->purgeByTags(['asset-42']);

        self::assertSame(['/img/42/*'], $this->commands[0]['InvalidationBatch']['Paths']['Items']);
    }

    public function testPurgeByUrlUsesPathWithTrailingWildcard(): void
    {
        $this->client()->purgeByUrl('https://cdn.example.com/var/assets/a%20b.jpg');

        self::assertSame(['/var/assets/a%20b.jpg*'], $this->commands[0]['InvalidationBatch']['Paths']['Items']);
    }

    public function testEmptyTagsSendNothing(): void
    {
        $this->client()->purgeByTags([]);

        self::assertSame([], $this->commands);
    }

    public function testSdkErrorPropagates(): void
    {
        $client = $this->client();
        $this->handler = new MockHandler();
        // Replace the appended callback with a failing one.
        $sdk = new CloudFrontClient(['region' => 'us-east-1', 'version' => '2020-05-31', 'credentials' => false, 'handler' => $this->handler]);
        $this->handler->append(static fn (CommandInterface $cmd) => new AwsException('AccessDenied', $cmd));
        $resolver = $this->createMock(AssetThumbnailDirectoryResolverInterface::class);
        $client = new CloudFrontPurgeClient($sdk, new NullLogger(), new TagToPathMapper($resolver, new NullLogger()), 'E1');

        $this->expectException(AwsException::class);

        $client->purgeByTag('asset-1');
    }
}
