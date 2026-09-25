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

namespace CORS\Bundle\CdnBundle\Purge;

/**
 * Resolves the public thumbnail directory of an asset, e.g. "/Car Images/42/" for
 * asset 42 in folder "/Car Images/". Returns null when the asset no longer exists.
 */
interface AssetThumbnailDirectoryResolverInterface
{
    public function resolve(int $assetId): ?string;
}
