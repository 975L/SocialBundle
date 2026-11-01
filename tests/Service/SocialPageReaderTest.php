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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class SocialPageReaderTest extends TestCase
{
    // An address needing no DNS, the reader resolving the host before it asks: the suite runs offline
    private const string PAGE = 'https://93.184.216.34/page';

    private function read(string $html, int $status = 200): SocialContent
    {
        return new SocialPageReader(new MockHttpClient(new MockResponse($html, ['http_code' => $status])))->read(self::PAGE);
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
        $this->assertSame(sha1(self::PAGE), $content->sourceId);
    }

    // A page with no Open Graph tags still has its title, and its own url
    public function testThePageTitleStandsInForAMissingOgTitle(): void
    {
        $content = $this->read('<html><head><title>Accueil</title></head></html>');

        $this->assertSame('Accueil', $content->title);
        $this->assertSame(self::PAGE, $content->url);
        $this->assertNull($content->imageUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function ownAddressesNoPostMayLinkTo(): iterable
    {
        yield 'javascript' => ['javascript:alert(1)'];
        yield 'mixed case' => ['JaVaScRiPt:alert(1)'];
        yield 'encoded by the page' => ['java&#115;cript:alert(1)'];
        yield 'tab in the scheme' => ['java&#9;script:alert(1)'];
        yield 'data' => ['data:text/html,x'];
        yield 'mailto' => ['mailto:a@example.org'];
        yield 'relative' => ['/histoires/loup'];
        yield 'protocol-relative' => ['//example.org/histoires/loup'];
        yield 'empty' => [''];
        yield 'a scheme and nothing else' => ['https://'];
        yield 'a space in the host' => ['https://exa mple.org/'];
        yield 'too long for the column' => ['https://example.org/' . str_repeat('a', 2048)];
    }

    // What a third party writes in its page goes out to the networks and the shared calendar's feed: anything a post could not hold falls back on the address asked for
    #[DataProvider('ownAddressesNoPostMayLinkTo')]
    public function testThePageCannotNameAnAddressNoPostMayLinkTo(string $ogUrl): void
    {
        $content = $this->read(sprintf('<html><head><title>Accueil</title><meta property="og:url" content="%s"></head></html>', $ogUrl));

        $this->assertSame(self::PAGE, $content->url);
    }

    // No image rather than one no publisher could ever read, or a post impossible to save: the media check then says so
    #[DataProvider('ownAddressesNoPostMayLinkTo')]
    public function testThePageCannotNameAnImageNoPostMayHold(string $ogImage): void
    {
        $content = $this->read(sprintf('<html><head><title>Accueil</title><meta property="og:image" content="%s"></head></html>', $ogImage));

        $this->assertNull($content->imageUrl);
    }

    public function testAnOwnAddressInCapitalsIsKept(): void
    {
        $content = $this->read('<html><head><title>Accueil</title><meta property="og:url" content="HTTPS://example.org/loup"></head></html>');

        $this->assertSame('HTTPS://example.org/loup', $content->url);
    }

    // The fallback must be saveable too: no post is prepared on an address its own constraint refuses
    public function testARequestedAddressNoPostMayLinkToIsNotRead(): void
    {
        $client = new MockHttpClient(function (): MockResponse {
            $this->fail('The request reached the network.');
        });

        $this->expectExceptionMessage('cannot be the link of a post');

        new SocialPageReader($client)->read('https://93.184.216.34/a b');
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
