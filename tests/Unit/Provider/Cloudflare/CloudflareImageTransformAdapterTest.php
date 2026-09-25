<?php

declare(strict_types=1);

namespace CORS\Bundle\CdnBundle\Tests\Unit\Provider\Cloudflare;

use CORS\Bundle\CdnBundle\Provider\Cloudflare\CloudflareImageTransformAdapter;
use PHPUnit\Framework\TestCase;
use Pimcore\Cdn\AssetWebPath;
use Pimcore\Cdn\CropRegion;
use Pimcore\Cdn\ThumbnailTransform;

final class CloudflareImageTransformAdapterTest extends TestCase
{
    private function adapter(string $baseUrl = 'https://cdn.example.com/'): CloudflareImageTransformAdapter
    {
        return new CloudflareImageTransformAdapter(new AssetWebPath(), $baseUrl);
    }

    public function testContainMapsToScaleDown(): void
    {
        $url = $this->adapter()->buildUrl(
            '/var/assets/Car Images/mötley.jpg',
            new ThumbnailTransform(width: 800, height: 600, fit: 'bounds', format: 'auto', quality: 80),
        );

        self::assertSame(
            'https://cdn.example.com/cdn-cgi/image/width=800,height=600,fit=scale-down,format=auto,quality=80/var/assets/Car%20Images/m%C3%B6tley.jpg',
            $url,
        );
    }

    public function testCoverWithDpr(): void
    {
        $url = $this->adapter()->buildUrl(
            '/var/assets/a.png',
            new ThumbnailTransform(width: 400, height: 400, fit: 'cover', format: 'webp', dpr: 2),
        );

        self::assertSame('https://cdn.example.com/cdn-cgi/image/width=400,height=400,fit=cover,format=webp,dpr=2/var/assets/a.png', $url);
    }

    public function testResizeWithBothDimensionsSqueezes(): void
    {
        $url = $this->adapter()->buildUrl('/var/assets/a.jpg', new ThumbnailTransform(width: 100, height: 50));

        self::assertSame('https://cdn.example.com/cdn-cgi/image/width=100,height=50,fit=squeeze/var/assets/a.jpg', $url);
    }

    public function testScaleByWidthKeepsRatio(): void
    {
        $url = $this->adapter()->buildUrl('/var/assets/a.jpg', new ThumbnailTransform(width: 300, format: 'jpg'));

        self::assertSame('https://cdn.example.com/cdn-cgi/image/width=300,fit=scale-down,format=jpeg/var/assets/a.jpg', $url);
    }

    public function testCropEmitsTrimBeforeResize(): void
    {
        $url = $this->adapter()->buildUrl(
            '/var/assets/a.jpg',
            new ThumbnailTransform(width: 200, crop: new CropRegion(10, 20, 300, 400)),
        );

        self::assertSame(
            'https://cdn.example.com/cdn-cgi/image/trim.left=10,trim.top=20,trim.width=300,trim.height=400,width=200,fit=scale-down/var/assets/a.jpg',
            $url,
        );
    }

    public function testNoOptionsReturnsPlainCdnUrl(): void
    {
        $url = $this->adapter()->buildUrl('/var/assets/a.jpg', new ThumbnailTransform());

        self::assertSame('https://cdn.example.com/var/assets/a.jpg', $url);
    }

    public function testMissingBaseUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->adapter('')->buildUrl('/var/assets/a.jpg', new ThumbnailTransform(width: 1));
    }
}
