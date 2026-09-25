<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Pimcore's CdnSurrogateKeyListener writes Cache-Tag space-separated (the Fastly
 * Surrogate-Key convention). Cloudflare, CloudFront (CacheTagConfig) and Cloud CDN
 * read comma-separated tags, so rewrite the header for those providers.
 * With CDN_PROVIDER=chain the header is rewritten when any chained provider needs it;
 * Fastly reads Surrogate-Key, and Azure has no tags, so neither minds the commas.
 * Runs at priority -10, after Pimcore's listener at 0.
 */
final class CacheTagHeaderRewriteListener implements EventSubscriberInterface
{
    private const COMMA_PROVIDERS = ['cloudflare', 'google', 'aws'];

    /**
     * @param list<string> $chainProviders
     */
    public function __construct(
        #[Autowire('%env(CDN_PROVIDER)%')]
        private readonly string $provider,
        #[Autowire('%cors_cdn.chain.providers%')]
        private readonly array $chainProviders = [],
    ) {
    }

    #[\Override]
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onKernelResponse', -10]];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$this->needsCommas()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $value = $headers->get('Cache-Tag');
        if ($value === null || $value === '') {
            return;
        }

        $tags = preg_split('/[\s,]+/', trim($value)) ?: [];
        $headers->set('Cache-Tag', implode(',', array_filter($tags, static fn (string $t): bool => $t !== '')));
    }

    private function needsCommas(): bool
    {
        $providers = $this->provider === 'chain' ? $this->chainProviders : [$this->provider];

        return array_intersect($providers, self::COMMA_PROVIDERS) !== [];
    }
}
