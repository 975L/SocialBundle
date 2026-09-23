<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\SocialPageReader;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SocialPageReaderTest extends TestCase
{
    private function read(string $html, int $status = 200): SocialContent
    {
        return new SocialPageReader(new MockHttpClient(new MockResponse($html, ['http_code' => $status])))->read('https://example.org/page');
    }

    public function testTheOpenGraphTagsMakeTheContent(): void
    {
        $content = $this->read('<html><head>
            <meta property="og:title" content="Le petit loup">
            <meta property="og:description" content="Une histoire à lire le soir">
            <meta property="og:image" content="https://example.org/loup.png">
            <meta property="og:url" content="https://example.org/histoires/loup">
        </head></html>');

        $this->assertSame('Le petit loup', $content->title);
        $this->assertSame('https://example.org/histoires/loup', $content->url);
        $this->assertSame('https://example.org/loup.png', $content->imageUrl);
        $this->assertSame(['description' => 'Une histoire à lire le soir'], $content->variables);
        $this->assertSame(sha1('https://example.org/page'), $content->sourceId);
    }

    // A page with no Open Graph tags still has its title, and its own url
    public function testThePageTitleStandsInForAMissingOgTitle(): void
    {
        $content = $this->read('<html><head><title>Accueil</title></head></html>');

        $this->assertSame('Accueil', $content->title);
        $this->assertSame('https://example.org/page', $content->url);
        $this->assertNull($content->imageUrl);
    }

    public function testAPageWithNoTitleIsRefused(): void
    {
        $this->expectExceptionMessage('carries no title');

        $this->read('<html><head></head></html>');
    }

    public function testAPageThatDoesNotAnswerIsRefused(): void
    {
        $this->expectExceptionMessage('answered 404');

        $this->read('', 404);
    }
}
