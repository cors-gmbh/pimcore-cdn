<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\Provider\Aws;

use Aws\CloudFront\CloudFrontClient;
use CORS\Bundle\CdnBundle\Purge\TagToPathMapper;
use Pimcore\Cdn\PurgeClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * CloudFront invalidations via the AWS SDK (default credential chain).
 *
 * With tag_invalidation enabled (default) tags are sent as "#tag" items, which requires
 * a CacheTagConfig on the distribution whose HeaderName is "Cache-Tag". Without it the
 * tags are mapped to path prefixes via TagToPathMapper.
 */
#[AutoconfigureTag('pimcore.cdn.purge_client', ['provider' => 'aws'])]
final class CloudFrontPurgeClient implements PurgeClientInterface
{
    /** Conservative batch size; CloudFront counts every item against the in-progress quota. */
    public const MAX_ITEMS_PER_REQUEST = 500;

    public function __construct(
        private readonly CloudFrontClient $cloudFront,
        private readonly LoggerInterface $logger,
        private readonly TagToPathMapper $mapper,
        #[Autowire('%cors_cdn.aws.distribution_id%')]
        private readonly string $distributionId,
        #[Autowire('%cors_cdn.aws.tag_invalidation%')]
        private readonly bool $tagInvalidation = true,
    ) {
    }

    #[\Override]
    public function purgeByTag(string $tag): void
    {
        $this->purgeByTags([$tag]);
    }

    #[\Override]
    public function purgeByTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));
        if ($tags === []) {
            return;
        }

        $items = $this->tagInvalidation
            ? array_map(static fn (string $t): string => '#' . $t, $tags)
            : $this->mapper->map($tags);

        $this->invalidate($items);
    }

    #[\Override]
    public function purgeByUrl(string $url): void
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        // CloudFront may cache query-string variants; the trailing wildcard catches them all.
        $this->invalidate([$path . '*']);
    }

    /**
     * @param list<string> $items Paths (already URL-encoded where needed) or "#tag" items
     */
    private function invalidate(array $items): void
    {
        $items = array_values(array_unique($items));
        if ($items === []) {
            return;
        }

        foreach (array_chunk($items, self::MAX_ITEMS_PER_REQUEST) as $chunk) {
            try {
                $this->cloudFront->createInvalidation([
                    'DistributionId' => $this->distributionId,
                    'InvalidationBatch' => [
                        'CallerReference' => uniqid('pimcore-cdn-', true),
                        'Paths' => [
                            'Quantity' => count($chunk),
                            'Items' => $chunk,
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('CloudFront invalidation failed. Items: {items}, Error: {error}', [
                    'items' => implode(', ', $chunk),
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        }
    }
}
