<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\Purge;

use Pimcore\Config;
use Pimcore\Model\Asset;

/**
 * Pimcore stores thumbnails under {asset dir}/{id}/(image|video)-thumb__{id}__{config}/…
 * and serves them below assets.frontend_prefixes.thumbnail.
 */
final class PimcoreAssetThumbnailDirectoryResolver implements AssetThumbnailDirectoryResolverInterface
{
    #[\Override]
    public function resolve(int $assetId): ?string
    {
        $asset = Asset::getById($assetId);
        if ($asset === null) {
            return null;
        }

        $prefix = (string) (Config::getSystemConfiguration('assets')['frontend_prefixes']['thumbnail'] ?? '');

        return rtrim($prefix, '/') . rtrim($asset->getPath(), '/') . '/' . $assetId . '/';
    }
}
