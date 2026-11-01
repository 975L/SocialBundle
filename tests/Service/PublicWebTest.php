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
use c975L\SocialBundle\Service\PublicWeb;
use c975L\SocialBundle\Service\SocialImageExporter;
use c975L\SocialBundle\Service\SocialPageReader;
use c975L\UiBundle\Model\SocialContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

// Neither the page an editor names nor the image a page names makes the server read what only it can reach
class PublicWebTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function forbiddenAddresses(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1/admin'];
        yield 'loopback v6' => ['http://[::1]/admin'];
        yield 'private network' => ['http://10.0.0.1/'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'];
        yield 'a file' => ['file:///etc/passwd'];
        yield 'another protocol' => ['gopher://example.org/'];
        yield 'no scheme' => ['example.org/page'];
    }

    #[DataProvider('forbiddenAddresses')]
    public function testThePageReaderRefusesIt(string $url): void
    {
        $this->expectException(\RuntimeException::class);

        new SocialPageReader($this->unreachedClient())->read($url);
    }

    #[DataProvider('forbiddenAddresses')]
    public function testTheImageExporterRefusesIt(string $url): void
    {
        $exporter = new SocialImageExporter($this->unreachedClient(), $this->createStub(SiteUrlResolver::class), sys_get_temp_dir(), $this->createStub(ConfigServiceInterface::class));

        $this->expectException(\RuntimeException::class);

        $exporter->read(new SocialContent('1', 'Title', 'https://93.184.216.34', imageUrl: $url));
    }

    // A public page cannot hand the request over to a private address
    public function testARedirectToAPrivateAddressIsRefused(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 302, 'redirect_url' => 'http://127.0.0.1/admin', 'response_headers' => ['location: http://127.0.0.1/admin']]));

        $this->expectException(TransportException::class);

        new SocialPageReader($client)->read('https://93.184.216.34/page');
    }

    public function testAPublicAddressIsRead(): void
    {
        $client = new MockHttpClient(new MockResponse('<title>Accueil</title>'));

        $this->assertSame('Accueil', new SocialPageReader($client)->read('https://93.184.216.34/page')->title);
    }

    public function testAnAddressAPostMayLinkTo(): void
    {
        foreach (['https://example.org/a?b=c#d', 'http://localhost:8000/a', 'HTTP://example.org', 'https://example.org/é'] as $url) {
            $this->assertTrue(PublicWeb::isAddress($url), $url);
        }
    }

    public function testAnAddressAPostMayNotLinkTo(): void
    {
        foreach (['', 'javascript:alert(1)', "java\tscript:alert(1)", 'data:text/html,x', 'vbscript:x', 'mailto:a@example.org', 'tel:+33600000000', 'ftp://example.org', '/path', '//example.org', 'http:foo', 'https://', "https://example.org/\n", 'https://example.org/' . str_repeat('a', 2048)] as $url) {
            $this->assertFalse(PublicWeb::isAddress($url), $url);
        }
    }

    // Fails the test the moment a request gets through
    private function unreachedClient(): MockHttpClient
    {
        return new MockHttpClient(function (): MockResponse {
            $this->fail('The request reached the network.');
        });
    }
}
