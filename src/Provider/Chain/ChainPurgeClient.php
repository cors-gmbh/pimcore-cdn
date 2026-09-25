<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\Provider\Chain;

use Pimcore\Cdn\PurgeClientInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Fans every purge out to several providers, for setups with stacked CDNs
 * (e.g. Cloudflare in front of Azure Front Door). Selected via CDN_PROVIDER=chain.
 *
 * Providers are purged in configured order, so list the layer closest to the origin
 * first: otherwise the outer CDN may refetch the stale object from the inner one.
 *
 * A failing provider does not stop the others; the first failure is re-thrown after
 * all providers ran, so Messenger retries the message. Purges are idempotent, so
 * repeating them on the providers that already succeeded is harmless.
 */
#[AutoconfigureTag('pimcore.cdn.purge_client', ['provider' => 'chain'])]
final class ChainPurgeClient implements PurgeClientInterface
{
    /**
     * @param list<string> $providers
     */
    public function __construct(
        #[AutowireLocator('pimcore.cdn.purge_client', 'provider')]
        private readonly ContainerInterface $clients,
        #[Autowire('%cors_cdn.chain.providers%')]
        private readonly array $providers,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function purgeByTag(string $tag): void
    {
        $this->each(static fn (PurgeClientInterface $client) => $client->purgeByTag($tag));
    }

    #[\Override]
    public function purgeByTags(array $tags): void
    {
        $this->each(static fn (PurgeClientInterface $client) => $client->purgeByTags($tags));
    }

    #[\Override]
    public function purgeByUrl(string $url): void
    {
        $this->each(static fn (PurgeClientInterface $client) => $client->purgeByUrl($url));
    }

    /**
     * @param \Closure(PurgeClientInterface): void $purge
     *
     * @throws \Throwable the first provider failure, after all providers were tried
     */
    private function each(\Closure $purge): void
    {
        if ($this->providers === []) {
            throw new \LogicException('CDN_PROVIDER is "chain" but cors_cdn.chain.providers is empty.');
        }

        $failure = null;
        foreach ($this->providers as $provider) {
            if ($provider === 'chain' || !$this->clients->has($provider)) {
                throw new \LogicException(sprintf('CDN chain provider "%s" is not a registered purge client.', $provider));
            }

            try {
                /** @var PurgeClientInterface $client */
                $client = $this->clients->get($provider);
                $purge($client);
            } catch (\Throwable $e) {
                $this->logger->error('CDN chain: purge on provider {provider} failed: {error}', [
                    'provider' => $provider,
                    'error' => $e->getMessage(),
                ]);
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
