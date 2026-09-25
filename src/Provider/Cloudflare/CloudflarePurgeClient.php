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

use CORS\Bundle\CdnBundle\Purge\AbstractHttpPurgeClient;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Purges via POST /zones/{zone}/purge_cache. Tag purge needs Cache-Tag headers at the
 * edge (see CacheTagHeaderRewriteListener, Pimcore emits them space-separated).
 */
#[AutoconfigureTag('pimcore.cdn.purge_client', ['provider' => 'cloudflare'])]
final class CloudflarePurgeClient extends AbstractHttpPurgeClient
{
    /** Cloudflare caps tag and URL purges at 100 operations per request (500 URLs on Enterprise). */
    public const MAX_ITEMS_PER_REQUEST = 100;

    public function __construct(
        ClientInterface $httpClient,
        LoggerInterface $logger,
        #[Autowire('%cors_cdn.cloudflare.zone_id%')]
        private readonly string $zoneId,
        #[Autowire('%cors_cdn.cloudflare.api_token%')]
        private readonly string $apiToken,
        #[Autowire('%cors_cdn.cloudflare.api_base_url%')]
        private readonly string $apiBaseUrl,
    ) {
        parent::__construct($httpClient, $logger);
    }

    #[\Override]
    protected function providerName(): string
    {
        return 'Cloudflare';
    }

    #[\Override]
    public function purgeByTags(array $tags): void
    {
        $tags = array_values(array_unique($tags));
        if ($tags === []) {
            return;
        }

        $this->assertTagsHaveNoWhitespace($tags);

        foreach (array_chunk($tags, self::MAX_ITEMS_PER_REQUEST) as $chunk) {
            $this->purge(['tags' => $chunk]);
        }
    }

    #[\Override]
    public function purgeByUrl(string $url): void
    {
        $this->purge(['files' => [$url]]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function purge(array $payload): void
    {
        $url = sprintf('%s/zones/%s/purge_cache', rtrim($this->apiBaseUrl, '/'), $this->zoneId);

        $response = $this->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiToken,
                'Accept' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $body = $this->decodeJson($response);
        if (($body['success'] ?? true) === false) {
            $errors = [];
            foreach (is_array($body['errors'] ?? null) ? $body['errors'] : [] as $e) {
                if (is_array($e)) {
                    $message = is_scalar($e['message'] ?? null) ? (string) $e['message'] : 'unknown';
                    $code = is_scalar($e['code'] ?? null) ? (string) $e['code'] : '-';
                    $errors[] = sprintf('%s (%s)', $message, $code);
                }
            }

            $this->logger->error('Cloudflare purge rejected. Errors: {errors}', ['errors' => implode('; ', $errors)]);

            throw new \RuntimeException('Cloudflare purge rejected: ' . implode('; ', $errors));
        }
    }
}
