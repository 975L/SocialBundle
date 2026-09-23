<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

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

    private function exporter(?string $siteUrl = 'https://example.org'): SocialImageExporter
    {
        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn($siteUrl);

        return new SocialImageExporter(new MockHttpClient(), $siteUrlResolver, $this->projectDir);
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
}
