<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\SocialFontProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;

class SocialFontProviderTest extends TestCase
{
    private const string STYLESHEET = '@font-face { src: url(https://fonts.gstatic.com/s/family/v1/file.ttf) format("truetype"); }';

    private string $projectDir;

    /** @var list<string> */
    private array $requested = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/social-font-' . uniqid();
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    // A client answering each request in turn, what it was asked being recorded
    private function provider(MockResponse ...$responses): SocialFontProvider
    {
        $responses = array_values($responses);

        return new SocialFontProvider(new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->requested[] = $url;

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        }), $this->projectDir);
    }

    // The family's TTF is read from its stylesheet and kept under var/
    public function testAFamilyIsFetchedOnceAndKept(): void
    {
        $provider = $this->provider(new MockResponse(self::STYLESHEET), new MockResponse('ttf-bytes'));

        $path = $provider->path('Item Font');

        $this->assertSame('ttf-bytes', file_get_contents((string) $path));
        $this->assertSame([], glob(\dirname((string) $path) . '/*.tmp'));
        $this->assertStringStartsWith($this->projectDir . '/var/social-fonts/', (string) $path);
        $this->assertStringContainsString('family=Item%20Font', $this->requested[0]);
        $this->assertSame('https://fonts.gstatic.com/s/family/v1/file.ttf', $this->requested[1]);

        $this->assertSame($path, $provider->path('Item Font'));
        $this->assertCount(2, $this->requested);
    }

    // A name as a site's uploaded file carries it is the one Google Fonts knows
    public function testAFamilyIsNamedAsGoogleFontsDoes(): void
    {
        $this->provider(new MockResponse(self::STYLESHEET), new MockResponse('ttf-bytes'))->path('"item font webfont"');

        $this->assertStringContainsString('family=Item%20Font', $this->requested[0]);
        $this->assertStringNotContainsString('webfont', $this->requested[0]);
    }

    // A generic family or a font of the site's own is simply not there
    public function testAnUnknownFamilyHasNoFile(): void
    {
        $this->assertNull($this->provider(new MockResponse('', ['http_code' => 400]))->path('sans-serif'));
        $this->assertDirectoryDoesNotExist($this->projectDir . '/var/social-fonts');
    }

    // Google Fonts failing says nothing of the family: the story is refused for what happened, not for the font
    public function testAFailingApiThrows(): void
    {
        $this->expectException(ServerExceptionInterface::class);

        $this->provider(new MockResponse('', ['http_code' => 503]))->path('Item Font');
    }

    // A stylesheet naming no TTF, as one written for a browser does, gives nothing to draw with
    public function testAStylesheetWithoutTtfHasNoFile(): void
    {
        $this->assertNull($this->provider(new MockResponse('src: url(https://fonts.gstatic.com/s/file.woff2)'))->path('Item Font'));
    }
}
