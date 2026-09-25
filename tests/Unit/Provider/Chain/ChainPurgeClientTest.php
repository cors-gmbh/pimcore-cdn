<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Chain;

use CORS\Bundle\CdnBundle\Provider\Chain\ChainPurgeClient;
use Pimcore\Cdn\PurgeClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class ChainPurgeClientTest extends TestCase
{
    /**
     * @param array<string, PurgeClientInterface> $clients
     * @param list<string>                        $providers
     */
    private function chain(array $clients, array $providers, ?LoggerInterface $logger = null): ChainPurgeClient
    {
        $factories = [];
        foreach ($clients as $key => $client) {
            $factories[$key] = static fn (): PurgeClientInterface => $client;
        }

        return new ChainPurgeClient(new ServiceLocator($factories), $providers, $logger ?? new NullLogger());
    }

    public function testFansOutInConfiguredOrder(): void
    {
        /** @var \ArrayObject<int, string> $calls */
        $calls = new \ArrayObject();
        $client = static fn (string $name): PurgeClientInterface => new class($name, $calls) implements PurgeClientInterface {
            /** @param \ArrayObject<int, string> $calls */
            public function __construct(private readonly string $name, private readonly \ArrayObject $calls)
            {
            }

            public function purgeByTag(string $tag): void
            {
                $this->calls[] = $this->name . ':tag:' . $tag;
            }

            public function purgeByTags(array $tags): void
            {
                $this->calls[] = $this->name . ':tags:' . implode(',', $tags);
            }

            public function purgeByUrl(string $url): void
            {
                $this->calls[] = $this->name . ':url:' . $url;
            }
        };

        $chain = $this->chain(['cloudflare' => $client('cf'), 'azure' => $client('az')], ['azure', 'cloudflare']);
        $chain->purgeByTags(['asset-1', 'asset-2']);
        $chain->purgeByTag('asset-3');
        $chain->purgeByUrl('https://cdn.example.com/var/assets/a.jpg');

        self::assertSame([
            'az:tags:asset-1,asset-2',
            'cf:tags:asset-1,asset-2',
            'az:tag:asset-3',
            'cf:tag:asset-3',
            'az:url:https://cdn.example.com/var/assets/a.jpg',
            'cf:url:https://cdn.example.com/var/assets/a.jpg',
        ], $calls->getArrayCopy());
    }

    public function testUnlistedProvidersAreNotInstantiated(): void
    {
        $azure = $this->createMock(PurgeClientInterface::class);
        $azure->expects(self::once())->method('purgeByTags');

        $locator = new ServiceLocator([
            'azure' => static fn (): PurgeClientInterface => $azure,
            'google' => static function (): PurgeClientInterface {
                self::fail('Unlisted provider must not be instantiated.');
            },
        ]);

        (new ChainPurgeClient($locator, ['azure'], new NullLogger()))->purgeByTags(['a']);
    }

    public function testFailureDoesNotStopOtherProvidersAndIsRethrown(): void
    {
        $error = new \RuntimeException('Cloudflare down');
        $cloudflare = $this->createMock(PurgeClientInterface::class);
        $cloudflare->method('purgeByUrl')->willThrowException($error);
        $azure = $this->createMock(PurgeClientInterface::class);
        $azure->expects(self::once())->method('purgeByUrl');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        try {
            $this->chain(['cloudflare' => $cloudflare, 'azure' => $azure], ['cloudflare', 'azure'], $logger)
                ->purgeByUrl('https://x/y');
            self::fail('Expected the provider failure to be re-thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame($error, $e);
        }
    }

    public function testUnknownProviderThrows(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('"fastly"');

        $this->chain(['azure' => $this->createMock(PurgeClientInterface::class)], ['azure', 'fastly'])->purgeByTag('a');
    }

    public function testEmptyChainThrows(): void
    {
        $this->expectException(\LogicException::class);

        $this->chain([], [])->purgeByTag('a');
    }
}
