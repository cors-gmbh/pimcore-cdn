<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\Provider\Google;

use CORS\Bundle\CdnBundle\Purge\AbstractHttpPurgeClient;
use Google\Auth\FetchAuthTokenInterface;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Cloud CDN (external HTTP(S) load balancer) invalidation via
 * POST compute/v1/projects/{project}/global/urlMaps/{urlMap}/invalidateCache.
 * Auth via Application Default Credentials (service account JSON, Workload Identity, …).
 * The call returns an async Operation; only the HTTP status is evaluated.
 */
#[AutoconfigureTag('pimcore.cdn.purge_client', ['provider' => 'google'])]
final class GoogleCloudCdnPurgeClient extends AbstractHttpPurgeClient
{
    public const SCOPE = 'https://www.googleapis.com/auth/compute';

    /**
     * @param positive-int $maxTagsPerRequest enforced by the bundle configuration (min 1)
     */
    public function __construct(
        ClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly FetchAuthTokenInterface $credentials,
        #[Autowire('%cors_cdn.google.project%')]
        private readonly string $project,
        #[Autowire('%cors_cdn.google.url_map%')]
        private readonly string $urlMap,
        #[Autowire('%cors_cdn.google.api_base_url%')]
        private readonly string $apiBaseUrl,
        #[Autowire('%cors_cdn.google.max_tags_per_request%')]
        private readonly int $maxTagsPerRequest = 100,
    ) {
        parent::__construct($httpClient, $logger);
    }

    #[\Override]
    protected function providerName(): string
    {
        return 'Google Cloud CDN';
    }

    #[\Override]
    public function purgeByTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));
        if ($tags === []) {
            return;
        }

        $this->assertTagsHaveNoWhitespace($tags);

        foreach (array_chunk($tags, $this->maxTagsPerRequest) as $chunk) {
            $this->invalidate(['cacheTags' => $chunk]);
        }
    }

    #[\Override]
    public function purgeByUrl(string $url): void
    {
        $parts = parse_url($url);
        $path = $parts['path'] ?? '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }

        $rule = ['path' => $path];
        if (isset($parts['host']) && $parts['host'] !== '') {
            $rule['host'] = $parts['host'];
        }

        $this->invalidate($rule);
    }

    /**
     * @param array<string, mixed> $rule CacheInvalidationRule
     */
    private function invalidate(array $rule): void
    {
        $token = $this->credentials->fetchAuthToken();
        $accessToken = $token['access_token'] ?? null;
        if (!is_string($accessToken) || $accessToken === '') {
            throw new \RuntimeException('Google Cloud CDN purge: could not obtain an access token from Application Default Credentials.');
        }

        $url = sprintf(
            '%s/projects/%s/global/urlMaps/%s/invalidateCache',
            rtrim($this->apiBaseUrl, '/'),
            rawurlencode($this->project),
            rawurlencode($this->urlMap),
        );

        $this->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ],
            'json' => $rule,
        ]);
    }
}
