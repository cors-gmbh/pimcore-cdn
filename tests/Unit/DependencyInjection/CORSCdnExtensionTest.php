<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\DependencyInjection;

use CORS\Bundle\CdnBundle\DependencyInjection\CORSCdnExtension;
use CORS\Bundle\CdnBundle\Provider\Aws\CloudFrontPurgeClient;
use CORS\Bundle\CdnBundle\Provider\Azure\FrontDoorPurgeClient;
use CORS\Bundle\CdnBundle\Provider\Chain\ChainPurgeClient;
use CORS\Bundle\CdnBundle\Provider\Cloudflare\CloudflareImageTransformAdapter;
use CORS\Bundle\CdnBundle\Provider\Cloudflare\CloudflarePurgeClient;
use CORS\Bundle\CdnBundle\Provider\Google\GoogleCloudCdnPurgeClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\RegisterAutoconfigureAttributesPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Verifies the wiring Pimcore's CdnPurgeClientRegistry relies on, without booting a kernel.
 */
final class CORSCdnExtensionTest extends TestCase
{
    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->container = new ContainerBuilder();
        (new CORSCdnExtension())->load([['cloudflare' => ['zone_id' => 'z', 'api_token' => 't']]], $this->container);
        // The passes that turn "Fqcn: ~" YAML services plus #[AutoconfigureTag] into real tags.
        (new ResolveClassPass())->process($this->container);
        (new RegisterAutoconfigureAttributesPass())->process($this->container);
        (new ResolveInstanceofConditionalsPass())->process($this->container);
    }

    /**
     * @return array<string, string>
     */
    private function providers(string $tag, string $attr): array
    {
        $map = [];
        foreach ($this->container->findTaggedServiceIds($tag) as $id => $tags) {
            foreach ($tags as $t) {
                $map[$t[$attr]] = $id;
            }
        }

        return $map;
    }

    public function testAllPurgeClientsAreTaggedByProvider(): void
    {
        self::assertSame([
            'cloudflare' => CloudflarePurgeClient::class,
            'azure' => FrontDoorPurgeClient::class,
            'chain' => ChainPurgeClient::class,
            'google' => GoogleCloudCdnPurgeClient::class,
            'aws' => CloudFrontPurgeClient::class,
        ], $this->providers('pimcore.cdn.purge_client', 'provider'));
    }

    public function testCloudflareImageTransformAdapterIsTagged(): void
    {
        self::assertSame(
            ['cloudflare' => CloudflareImageTransformAdapter::class],
            $this->providers('pimcore.cdn.image_transform_adapter', 'optimizer'),
        );
    }

    public function testParametersAreExposed(): void
    {
        self::assertSame('z', $this->container->getParameter('cors_cdn.cloudflare.zone_id'));
        self::assertSame('skip', $this->container->getParameter('cors_cdn.path_only_purge.unmappable_tag_strategy'));
        self::assertTrue($this->container->getParameter('cors_cdn.aws.tag_invalidation'));
        self::assertSame(100, $this->container->getParameter('cors_cdn.google.max_tags_per_request'));
        self::assertSame([], $this->container->getParameter('cors_cdn.chain.providers'));
    }

    public function testChainCannotContainItself(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);

        (new CORSCdnExtension())->load([['chain' => ['providers' => ['azure', 'chain']]]], new ContainerBuilder());
    }
}
