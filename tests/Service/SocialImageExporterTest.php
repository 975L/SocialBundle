<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;

class SocialImageExporterTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/social-exporter-' . uniqid();
        mkdir($this->projectDir . '/public/medias', 0o775, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    // A WebP file of the given size, the format the site's own images are stored in
    private function webp(int $width, int $height): string
    {
        $path = $this->projectDir . '/public/medias/source-' . $width . 'x' . $height . '.webp';
        imagewebp(imagecreatetruecolor($width, $height), $path);

        return $path;
    }

    private function exporter(?string $siteUrl = 'https://example.org', string $format = 'framed', ?string $background = null): SocialImageExporter
    {
        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn($siteUrl);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => ['social-image-format' => $format, 'theme-color-background' => $background][$key] ?? null);

        return new SocialImageExporter(new MockHttpClient(), $siteUrlResolver, $this->projectDir, $configService);
    }

    /**
     * @return array{0: int, 1: int, mime: string}
     */
    private function exported(string $url): array
    {
        $size = getimagesize($this->projectDir . '/public/' . substr($url, \strlen('https://example.org/')));
        $this->assertNotFalse($size);

        return $size;
    }

    // Meta refuses WebP, so it gets a JPEG served from the site
    public function testTheImageIsCopiedAsAJpegUnderThePublicFolder(): void
    {
        $url = $this->exporter()->jpegUrl(new SocialContent('1', 'Title', 'https://example.org', imagePath: $this->webp(400, 400)));

        $this->assertStringStartsWith('https://example.org/medias/social/', (string) $url);
        $this->assertSame('image/jpeg', $this->exported((string) $url)['mime']);
    }

    // A 20:9 panorama is wider than Instagram takes: it gains bands rather than losing its sides
    public function testAPanoramaIsFramedToTheWidestAcceptedRatio(): void
    {
        $size = $this->exported((string) $this->exporter()->jpegUrl(new SocialContent('1', 'Title', 'https://example.org', imagePath: $this->webp(2000, 900)), 0.8, 1.91));

        $this->assertSame(1440, $size[0]);
        $this->assertEqualsWithDelta(1.91, $size[0] / $size[1], 0.01);
    }

    public function testAPortraitIsFramedToTheTallestAcceptedRatio(): void
    {
        $size = $this->exported((string) $this->exporter()->jpegUrl(new SocialContent('1', 'Title', 'https://example.org', imagePath: $this->webp(300, 600)), 0.8, 1.91));

        $this->assertEqualsWithDelta(0.8, $size[0] / $size[1], 0.01);
    }

    public function testAContentWithoutImageOrSiteUrlHasNoJpeg(): void
    {
        $this->assertNull($this->exporter()->jpegUrl(new SocialContent('1', 'Title', 'https://example.org')));
        $this->assertNull($this->exporter(null)->jpegUrl(new SocialContent('1', 'Title', 'https://example.org', imagePath: $this->webp(10, 10))));
    }

    // The site's square: one 1080 x 1080 visual everywhere, the picture centred on the site's own background
    public function testASquareIsDrawnOnTheSitesBackground(): void
    {
        $content = new SocialContent('1', 'Title', 'https://example.org/1', imagePath: $this->webp(600, 849));
        $exporter = $this->exporter(format: 'square', background: '#0c1f33');

        $size = $this->exported((string) $exporter->jpegUrl($content, 0.8, 1.91));
        $bytes = (string) $exporter->bytes($content);
        $image = imagecreatefromstring($bytes);
        $this->assertNotFalse($image);

        $this->assertSame([1080, 1080], [$size[0], $size[1]]);
        $this->assertSame([1080, 1080], [imagesx($image), imagesy($image)]);
        // A band pixel, left of the portrait: the background colour, give or take the JPEG compression
        $rgb = imagecolorat($image, 5, 540);
        $this->assertEqualsWithDelta([0x0C, 0x1F, 0x33], [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF], 6);
    }

    // Framed, the bytes a network uploads itself are the picture as it is
    public function testFramedBytesAreThePictureAsItIs(): void
    {
        $path = $this->webp(600, 849);

        $this->assertSame((string) file_get_contents($path), $this->exporter()->bytes(new SocialContent('1', 'Title', 'https://example.org/1', imagePath: $path)));
    }
}
