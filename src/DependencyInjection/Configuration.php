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

namespace CORS\Bundle\CdnBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * Provider selection itself is Pimcore's job (CDN_PROVIDER / CDN_IMAGE_OPTIMIZER);
 * this tree only holds the per-provider credentials and endpoints.
 */
final class Configuration implements ConfigurationInterface
{
    #[\Override]
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cors_cdn');
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();

        $root
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('cloudflare')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('zone_id')->defaultValue('%env(CLOUDFLARE_ZONE_ID)%')->end()
                        ->scalarNode('api_token')->defaultValue('%env(CLOUDFLARE_API_TOKEN)%')->end()
                        ->scalarNode('api_base_url')->defaultValue('https://api.cloudflare.com/client/v4')->end()
                    ->end()
                ->end()
                ->arrayNode('google')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('project')->defaultValue('%env(GOOGLE_CLOUD_PROJECT)%')->end()
                        ->scalarNode('url_map')->defaultValue('%env(GOOGLE_CDN_URL_MAP)%')->end()
                        ->scalarNode('api_base_url')->defaultValue('https://compute.googleapis.com/compute/v1')->end()
                        ->integerNode('max_tags_per_request')
                            ->info('Cache tags per invalidateCache call. Google does not document a hard limit; keep batches small.')
                            ->min(1)->defaultValue(100)
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('aws')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('distribution_id')->defaultValue('%env(AWS_CLOUDFRONT_DISTRIBUTION_ID)%')->end()
                        ->scalarNode('region')->defaultValue('us-east-1')->end()
                        ->booleanNode('tag_invalidation')
                            ->info('Send tags as "#tag" invalidation items. Requires a CacheTagConfig with HeaderName "Cache-Tag" on the distribution. false = map tags to path prefixes instead.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('azure')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('resource_id')
                            ->info('ARM resource id of the Front Door endpoint: /subscriptions/{sub}/resourceGroups/{rg}/providers/Microsoft.Cdn/profiles/{profile}/afdEndpoints/{endpoint}')
                            ->defaultValue('%env(AZURE_FRONTDOOR_RESOURCE_ID)%')
                        ->end()
                        ->scalarNode('tenant_id')->defaultValue('%env(AZURE_TENANT_ID)%')->end()
                        ->scalarNode('client_id')->defaultValue('%env(AZURE_CLIENT_ID)%')->end()
                        ->scalarNode('client_secret')->defaultValue('%env(AZURE_CLIENT_SECRET)%')->end()
                        ->scalarNode('api_version')->defaultValue('2024-02-01')->end()
                        ->scalarNode('management_base_url')->defaultValue('https://management.azure.com')->end()
                        ->scalarNode('login_base_url')->defaultValue('https://login.microsoftonline.com')->end()
                    ->end()
                ->end()
                ->arrayNode('chain')
                    ->info('Providers purged by CDN_PROVIDER=chain, in order. List the layer closest to the origin first.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('providers')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $v): bool => $v === 'chain')
                                    ->thenInvalid('The chain provider cannot contain itself.')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('path_only_purge')
                    ->info('Behaviour for providers without cache tags (aws, azure) when a tag cannot be mapped to a path prefix.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->enumNode('unmappable_tag_strategy')
                            ->values(['skip', 'purge_all'])
                            ->defaultValue('skip')
                        ->end()
                    ->end()
                ->end()
        ;

        return $treeBuilder;
    }
}
