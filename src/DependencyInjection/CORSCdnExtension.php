<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 */

namespace CORS\Bundle\CdnBundle\DependencyInjection;

use Aws\CloudFront\CloudFrontClient;
use CORS\Bundle\CdnBundle\Provider\Aws\CloudFrontPurgeClient;
use CORS\Bundle\CdnBundle\Provider\Google\GoogleCloudCdnPurgeClient;
use Google\Auth\ApplicationDefaultCredentials;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

final class CORSCdnExtension extends Extension
{
    public const PURGE_CLIENT_TAG = 'pimcore.cdn.purge_client';

    #[\Override]
    public function getAlias(): string
    {
        return 'cors_cdn';
    }

    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration($this->getConfiguration($configs, $container), $configs);

        foreach (['cloudflare', 'google', 'aws', 'azure', 'chain', 'path_only_purge'] as $section) {
            foreach ($config[$section] as $key => $value) {
                $container->setParameter(sprintf('cors_cdn.%s.%s', $section, $key), $value);
            }
        }

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        // Providers that need an SDK are registered only when it is installed, so a
        // Cloudflare-only project does not have to pull google/auth or the AWS SDK.
        $this->registerGoogle($container);
        $this->registerAws($container);
    }

    private function registerGoogle(ContainerBuilder $container): void
    {
        if (!class_exists(ApplicationDefaultCredentials::class)) {
            return;
        }

        $credentials = new Definition(\Google\Auth\FetchAuthTokenInterface::class);
        $credentials->setFactory([ApplicationDefaultCredentials::class, 'getCredentials']);
        $credentials->setArguments([GoogleCloudCdnPurgeClient::SCOPE]);
        $credentials->setPublic(false);
        $container->setDefinition('cors_cdn.google.credentials', $credentials);

        $client = new Definition(GoogleCloudCdnPurgeClient::class);
        $client->setAutowired(true);
        $client->setArgument('$credentials', new Reference('cors_cdn.google.credentials'));
        $client->setArgument('$project', '%cors_cdn.google.project%');
        $client->setArgument('$urlMap', '%cors_cdn.google.url_map%');
        $client->setArgument('$apiBaseUrl', '%cors_cdn.google.api_base_url%');
        $client->setArgument('$maxTagsPerRequest', '%cors_cdn.google.max_tags_per_request%');
        $client->setPublic(false);
        $client->addTag(self::PURGE_CLIENT_TAG, ['provider' => 'google']);
        $container->setDefinition(GoogleCloudCdnPurgeClient::class, $client);
    }

    private function registerAws(ContainerBuilder $container): void
    {
        if (!class_exists(CloudFrontClient::class)) {
            return;
        }

        $sdk = new Definition(CloudFrontClient::class, [[
            'region' => '%cors_cdn.aws.region%',
            'version' => '2020-05-31',
        ]]);
        $sdk->setPublic(false);
        $container->setDefinition('cors_cdn.aws.cloudfront_client', $sdk);

        $client = new Definition(CloudFrontPurgeClient::class);
        $client->setAutowired(true);
        $client->setArgument('$cloudFront', new Reference('cors_cdn.aws.cloudfront_client'));
        $client->setArgument('$distributionId', '%cors_cdn.aws.distribution_id%');
        $client->setArgument('$tagInvalidation', '%cors_cdn.aws.tag_invalidation%');
        $client->setPublic(false);
        $client->addTag(self::PURGE_CLIENT_TAG, ['provider' => 'aws']);
        $container->setDefinition(CloudFrontPurgeClient::class, $client);
    }
}
