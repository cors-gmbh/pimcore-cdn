<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;

final class CORSCdnBundle extends AbstractPimcoreBundle
{
    #[\Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (null === $this->extension) {
            $extension = $this->createContainerExtension();
            $this->extension = $extension ?? false;
        }

        return $this->extension ?: null;
    }

    #[\Override]
    public function getNiceName(): string
    {
        return 'CORS - CDN';
    }

    #[\Override]
    public function getDescription(): string
    {
        return 'Purge clients and image transform adapters for Pimcore\'s CDN integration: Cloudflare, Google Cloud CDN, AWS CloudFront, Azure Front Door.';
    }
}
