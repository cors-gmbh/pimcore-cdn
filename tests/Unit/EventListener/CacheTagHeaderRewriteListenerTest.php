<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\EventListener;

use CORS\Bundle\CdnBundle\EventListener\CacheTagHeaderRewriteListener;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CacheTagHeaderRewriteListenerTest extends TestCase
{
    /**
     * @param list<string> $chain
     */
    private function dispatch(string $provider, ?string $cacheTag, array $chain = []): Response
    {
        $response = new Response();
        if ($cacheTag !== null) {
            $response->headers->set('Surrogate-Key', $cacheTag);
            $response->headers->set('Cache-Tag', $cacheTag);
        }
        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            HttpKernelInterface::MAIN_REQUEST,
            $response,
        );

        (new CacheTagHeaderRewriteListener($provider, $chain))->onKernelResponse($event);

        return $response;
    }

    public function testRewritesSpacesToCommasForCloudflare(): void
    {
        $r = $this->dispatch('cloudflare', 'asset-42 thumb-teaser  asset-42-thumb-teaser');

        self::assertSame('asset-42,thumb-teaser,asset-42-thumb-teaser', $r->headers->get('Cache-Tag'));
        self::assertSame('asset-42 thumb-teaser  asset-42-thumb-teaser', $r->headers->get('Surrogate-Key'), 'Surrogate-Key untouched');
    }

    public function testLeavesHeaderAloneForOtherProviders(): void
    {
        self::assertSame('a b', $this->dispatch('fastly', 'a b')->headers->get('Cache-Tag'));
        self::assertSame('a b', $this->dispatch('', 'a b')->headers->get('Cache-Tag'));
    }

    public function testChainRewritesWhenAnyProviderNeedsCommas(): void
    {
        self::assertSame('a,b', $this->dispatch('chain', 'a b', ['azure', 'cloudflare'])->headers->get('Cache-Tag'));
        self::assertSame('a b', $this->dispatch('chain', 'a b', ['azure', 'fastly'])->headers->get('Cache-Tag'));
    }

    public function testNoHeaderNoop(): void
    {
        self::assertFalse($this->dispatch('cloudflare', null)->headers->has('Cache-Tag'));
    }

    public function testRunsAfterPimcoreListener(): void
    {
        self::assertSame(-10, CacheTagHeaderRewriteListener::getSubscribedEvents()['kernel.response'][1]);
    }
}
