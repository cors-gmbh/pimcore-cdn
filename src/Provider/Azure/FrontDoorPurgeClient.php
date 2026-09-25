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

namespace CORS\Bundle\CdnBundle\Provider\Azure;

use CORS\Bundle\CdnBundle\Purge\AbstractHttpPurgeClient;
use CORS\Bundle\CdnBundle\Purge\TagToPathMapper;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Azure Front Door (Standard/Premium) purge via ARM:
 * POST https://management.azure.com{resourceId}/purge?api-version=… {contentPaths, domains}.
 * Auth via Entra ID client-credentials flow; the token is cached in memory per process.
 * Front Door has no cache tags, tags go through TagToPathMapper.
 */
#[AutoconfigureTag('pimcore.cdn.purge_client', ['provider' => 'azure'])]
final class FrontDoorPurgeClient extends AbstractHttpPurgeClient
{
    public const MAX_PATHS_PER_REQUEST = 100;

    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    public function __construct(
        ClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly TagToPathMapper $mapper,
        #[Autowire('%cors_cdn.azure.resource_id%')]
        private readonly string $resourceId,
        #[Autowire('%cors_cdn.azure.tenant_id%')]
        private readonly string $tenantId,
        #[Autowire('%cors_cdn.azure.client_id%')]
        private readonly string $clientId,
        #[Autowire('%cors_cdn.azure.client_secret%')]
        private readonly string $clientSecret,
        #[Autowire('%cors_cdn.azure.api_version%')]
        private readonly string $apiVersion,
        #[Autowire('%cors_cdn.azure.management_base_url%')]
        private readonly string $managementBaseUrl = 'https://management.azure.com',
        #[Autowire('%cors_cdn.azure.login_base_url%')]
        private readonly string $loginBaseUrl = 'https://login.microsoftonline.com',
    ) {
        parent::__construct($httpClient, $logger);
    }

    #[\Override]
    protected function providerName(): string
    {
        return 'Azure Front Door';
    }

    #[\Override]
    public function purgeByTags(array $tags): void
    {
        $paths = $this->mapper->map($tags);
        if ($paths === []) {
            return;
        }

        foreach (array_chunk($paths, self::MAX_PATHS_PER_REQUEST) as $chunk) {
            $this->purge($chunk, []);
        }
    }

    #[\Override]
    public function purgeByUrl(string $url): void
    {
        $parts = parse_url($url);
        // Front Door purges are query-string agnostic, so the bare path is enough.
        $path = $parts['path'] ?? '/';
        $domains = isset($parts['host']) && $parts['host'] !== '' ? [$parts['host']] : [];

        $this->purge([$path], $domains);
    }

    /**
     * @param list<string> $contentPaths
     * @param list<string> $domains
     */
    private function purge(array $contentPaths, array $domains): void
    {
        $body = ['contentPaths' => $contentPaths];
        if ($domains !== []) {
            $body['domains'] = $domains;
        }

        $url = sprintf(
            '%s%s/purge?api-version=%s',
            rtrim($this->managementBaseUrl, '/'),
            $this->resourceId,
            rawurlencode($this->apiVersion),
        );

        $this->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->getAccessToken(),
                'Accept' => 'application/json',
            ],
            'json' => $body,
        ]);
    }

    private function getAccessToken(): string
    {
        if ($this->accessToken !== null && $this->tokenExpiresAt > time() + 60) {
            return $this->accessToken;
        }

        $url = sprintf('%s/%s/oauth2/v2.0/token', rtrim($this->loginBaseUrl, '/'), rawurlencode($this->tenantId));
        $response = $this->request('POST', $url, [
            'form_params' => [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => rtrim($this->managementBaseUrl, '/') . '/.default',
            ],
        ]);

        $data = $this->decodeJson($response);
        $token = $data['access_token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Azure Front Door purge: token endpoint returned no access_token.');
        }

        $this->accessToken = $token;
        $expiresIn = $data['expires_in'] ?? null;
        $this->tokenExpiresAt = time() + (is_numeric($expiresIn) ? (int) $expiresIn : 3600);

        return $token;
    }
}
