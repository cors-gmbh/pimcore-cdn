<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\Purge;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Translates Pimcore's cache tags into path patterns for CDNs that can only
 * invalidate by path (with a trailing wildcard).
 *
 *   asset-{id}                → {thumbDir}/*                              (needs the asset in DB)
 *   asset-{id}-thumb-{config} → {thumbDir}/image-thumb__{id}__{config}/*  (+ video-thumb)
 *   thumb-{config}            → unmappable (config name is not a path prefix)
 *   asset-path-{hash}         → unmappable (hash is one-way; originals are URL-purged anyway)
 *
 * Unmappable tags are skipped with a warning or, with strategy "purge_all",
 * collapsed into a single "/*".
 */
final class TagToPathMapper
{
    public const STRATEGY_SKIP = 'skip';

    public const STRATEGY_PURGE_ALL = 'purge_all';

    public function __construct(
        private readonly AssetThumbnailDirectoryResolverInterface $directoryResolver,
        private readonly LoggerInterface $logger,
        #[Autowire('%cors_cdn.path_only_purge.unmappable_tag_strategy%')]
        private readonly string $strategy = self::STRATEGY_SKIP,
    ) {
    }

    /**
     * @param string[] $tags
     *
     * @return list<string> Unencoded path patterns, deduplicated. May be empty.
     */
    public function map(array $tags): array
    {
        $paths = [];
        $unmappable = [];

        foreach (array_unique($tags) as $tag) {
            if (preg_match('/^asset-(\d+)-thumb-([a-zA-Z0-9_\-]+)$/', $tag, $m)) {
                $dir = $this->directoryResolver->resolve((int) $m[1]);
                if ($dir === null) {
                    $unmappable[] = $tag;

                    continue;
                }
                $paths[] = sprintf('%simage-thumb__%d__%s/*', $dir, (int) $m[1], $m[2]);
                $paths[] = sprintf('%svideo-thumb__%d__%s/*', $dir, (int) $m[1], $m[2]);

                continue;
            }

            if (preg_match('/^asset-(\d+)$/', $tag, $m)) {
                $dir = $this->directoryResolver->resolve((int) $m[1]);
                if ($dir === null) {
                    $unmappable[] = $tag;

                    continue;
                }
                $paths[] = $dir . '*';

                continue;
            }

            $unmappable[] = $tag;
        }

        if ($unmappable !== []) {
            if ($this->strategy === self::STRATEGY_PURGE_ALL) {
                $this->logger->warning('CDN tags {tags} cannot be mapped to paths, purging everything ("/*").', ['tags' => implode(', ', $unmappable)]);

                return ['/*'];
            }

            $this->logger->warning('CDN tags {tags} cannot be mapped to paths and were skipped.', ['tags' => implode(', ', $unmappable)]);
        }

        return array_values(array_unique($paths));
    }
}
