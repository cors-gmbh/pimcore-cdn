<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Purge;

use CORS\Bundle\CdnBundle\Purge\AssetThumbnailDirectoryResolverInterface;
use CORS\Bundle\CdnBundle\Purge\TagToPathMapper;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class TagToPathMapperTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function mapper(string $strategy = TagToPathMapper::STRATEGY_SKIP): TagToPathMapper
    {
        $resolver = new class implements AssetThumbnailDirectoryResolverInterface {
            public function resolve(int $assetId): ?string
            {
                return $assetId === 42 ? '/Car Images/42/' : null;
            }
        };
        $warnings = &$this->warnings;
        $logger = new class($warnings) extends AbstractLogger {
            /** @param list<string> $sink */
            public function __construct(private array &$sink)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->sink[] = (string) $level . ': ' . strtr((string) $message, ['{tags}' => $context['tags'] ?? '']);
            }
        };

        return new TagToPathMapper($resolver, $logger, $strategy);
    }

    public function testAssetTagMapsToThumbnailDirectory(): void
    {
        self::assertSame(['/Car Images/42/*'], $this->mapper()->map(['asset-42']));
    }

    public function testAssetThumbTagMapsToImageAndVideoDirectories(): void
    {
        self::assertSame(
            ['/Car Images/42/image-thumb__42__teaser/*', '/Car Images/42/video-thumb__42__teaser/*'],
            $this->mapper()->map(['asset-42-thumb-teaser']),
        );
    }

    public function testUnmappableTagsAreSkippedWithWarning(): void
    {
        $paths = $this->mapper()->map(['thumb-teaser', 'asset-path-abc', 'asset-99', 'asset-42']);

        self::assertSame(['/Car Images/42/*'], $paths);
        self::assertCount(1, $this->warnings);
        self::assertStringContainsString('thumb-teaser, asset-path-abc, asset-99', $this->warnings[0]);
        self::assertStringContainsString('skipped', $this->warnings[0]);
    }

    public function testPurgeAllStrategyCollapsesToRoot(): void
    {
        self::assertSame(['/*'], $this->mapper(TagToPathMapper::STRATEGY_PURGE_ALL)->map(['asset-42', 'thumb-teaser']));
    }

    public function testDeduplicates(): void
    {
        self::assertSame(['/Car Images/42/*'], $this->mapper()->map(['asset-42', 'asset-42']));
    }

    public function testEmptyInput(): void
    {
        self::assertSame([], $this->mapper()->map([]));
        self::assertSame([], $this->warnings);
    }
}
