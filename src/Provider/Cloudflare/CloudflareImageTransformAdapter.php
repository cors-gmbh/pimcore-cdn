<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * This source file is available under the MIT license
 *
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 * @license    https://opensource.org/license/mit MIT
 */

namespace CORS\Bundle\CdnBundle\Provider\Cloudflare;

use Pimcore\Cdn\AssetWebPath;
use Pimcore\Cdn\ImageTransformAdapterInterface;
use Pimcore\Cdn\ThumbnailTransform;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Builds Cloudflare Image Transformation URLs: {base}/cdn-cgi/image/{options}/{path}.
 * Requires "Transformations" to be enabled on the zone.
 */
#[AutoconfigureTag('pimcore.cdn.image_transform_adapter', ['optimizer' => 'cloudflare'])]
final class CloudflareImageTransformAdapter implements ImageTransformAdapterInterface
{
    public function __construct(
        private readonly AssetWebPath $assetWebPath,
        #[Autowire('%pimcore.cdn.base_url%')]
        private readonly string $baseUrl,
    ) {
    }

    #[\Override]
    public function buildUrl(string $originalPath, ThumbnailTransform $transform): string
    {
        if ($this->baseUrl === '') {
            throw new \RuntimeException(
                'CDN_BASE_URL must be set when CDN_IMAGE_OPTIMIZER=cloudflare (cannot build an absolute /cdn-cgi/image URL without it).',
            );
        }

        $options = $this->buildOptions($transform);
        $path = $this->assetWebPath->encode($originalPath);

        if ($options === []) {
            return rtrim($this->baseUrl, '/') . $path;
        }

        return sprintf('%s/cdn-cgi/image/%s%s', rtrim($this->baseUrl, '/'), implode(',', $options), $path);
    }

    /**
     * @return list<string>
     */
    private function buildOptions(ThumbnailTransform $t): array
    {
        $o = [];

        if ($t->crop !== null) {
            // Absolute crop rectangle in source pixels.
            $o[] = 'trim.left=' . $t->crop->x;
            $o[] = 'trim.top=' . $t->crop->y;
            $o[] = 'trim.width=' . $t->crop->width;
            $o[] = 'trim.height=' . $t->crop->height;
        }

        if ($t->width !== null) {
            $o[] = 'width=' . $t->width;
        }
        if ($t->height !== null) {
            $o[] = 'height=' . $t->height;
        }

        $fit = match ($t->fit) {
            // Pimcore contain (Fastly "bounds"): fit inside the box, never upscale.
            'bounds' => 'scale-down',
            'cover' => 'cover',
            // Pimcore resize forces exact dimensions when both are given (aspect ratio ignored).
            default => ($t->width !== null && $t->height !== null) ? 'squeeze' : 'scale-down',
        };
        if ($t->width !== null || $t->height !== null) {
            $o[] = 'fit=' . $fit;
        }

        if ($t->format !== null && $t->format !== '') {
            $o[] = 'format=' . ($t->format === 'jpg' ? 'jpeg' : $t->format);
        }
        if ($t->quality !== null) {
            $o[] = 'quality=' . $t->quality;
        }
        if ($t->dpr !== null) {
            $o[] = 'dpr=' . $t->dpr;
        }

        return $o;
    }
}
