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

use GuzzleHttp\ClientInterface;
use Pimcore\Cdn\PurgeClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Shared HTTP plumbing for provider purge clients.
 *
 * Every failure (transport exception, non-2xx status, provider-level error flag) is
 * re-thrown so Symfony Messenger sees the failure and applies the retry policy of the
 * pimcore_cdn_purge transport. Returning silently would mark the purge as handled and
 * leave a stale object at the edge forever.
 */
abstract class AbstractHttpPurgeClient implements PurgeClientInterface
{
    public function __construct(
        protected readonly ClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {
    }

    abstract protected function providerName(): string;

    #[\Override]
    public function purgeByTag(string $tag): void
    {
        $this->purgeByTags([$tag]);
    }

    /**
     * @param array<string, mixed> $options Guzzle request options
     *
     * @throws \Throwable when the request fails or the provider returns a non-2xx status
     */
    protected function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $options['http_errors'] = false;

        try {
            $response = $this->httpClient->request($method, $url, $options);
        } catch (\Throwable $e) {
            $this->logger->error('{provider} purge request threw an exception. Method: {method}, URL: {url}, Error: {error}', [
                'provider' => $this->providerName(),
                'method' => $method,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $this->logger->error('{provider} purge request failed. Method: {method}, URL: {url}, Status: {status}', [
                'provider' => $this->providerName(),
                'method' => $method,
                'url' => $url,
                'status' => $status,
            ]);

            throw new \RuntimeException(sprintf(
                '%s purge request failed with HTTP %d for %s %s',
                $this->providerName(),
                $status,
                $method,
                $url,
            ));
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        if ($body === '') {
            return [];
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Guard against whitespace inside tags: providers that use space-separated headers
     * would silently split such a tag and purge unrelated objects.
     *
     * @param string[] $tags
     */
    protected function assertTagsHaveNoWhitespace(array $tags): void
    {
        foreach ($tags as $tag) {
            if (preg_match('/\s/', $tag)) {
                throw new \InvalidArgumentException(sprintf('Cache tag "%s" must not contain whitespace.', $tag));
            }
        }
    }
}
