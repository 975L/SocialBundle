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
use c975L\SocialBundle\Service\MetaGraphClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MetaGraphClientTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses = [], ?string $pageToken = null, ?string $pageId = null): MetaGraphClient
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-meta-app-id', '123'],
            ['social-meta-app-secret', 'secret'],
            ['social-meta-page-token', $pageToken],
            ['social-meta-page-id', $pageId],
        ]);

        return new MetaGraphClient(new MockHttpClient(function (string $method, string $url) use (&$responses): MockResponse {
            $this->urls[] = $url;

            return array_shift($responses) ?? new MockResponse('{}');
        }), $configService);
    }

    public function testTheConsentScreenAsksForThePageAndInstagramPermissions(): void
    {
        $url = $this->client()->getAuthorizationUrl('https://example.org/callback', 'state');

        $this->assertStringStartsWith(MetaGraphClient::DIALOG, $url);
        $this->assertStringContainsString('pages_manage_posts', urldecode($url));
        $this->assertStringContainsString('instagram_content_publish', urldecode($url));
    }

    // Code, then a long-lived user token, then the Page and its token - one that does not expire - then the Instagram account linked to it
    public function testConnectTradesTheCodeForThePageTokenAndTheInstagramAccount(): void
    {
        $connection = $this->client([
            new MockResponse('{"access_token":"short"}'),
            new MockResponse('{"access_token":"long"}'),
            new MockResponse('{"data":[{"id":"55","access_token":"page-token"}]}'),
            new MockResponse('{"instagram_business_account":{"id":"77"},"id":"55"}'),
        ])->connect('code', 'https://example.org/callback');

        $this->assertSame(['pageId' => '55', 'pageToken' => 'page-token', 'instagramId' => '77'], $connection);
        $this->assertStringContainsString('fb_exchange_token=short', $this->urls[1]);
    }

    // An owner managing the Pages of several sites: the one this site already names is kept, whatever Meta lists first
    public function testConnectKeepsThePageTheSiteAlreadyNames(): void
    {
        $connection = $this->client([
            new MockResponse('{"access_token":"short"}'),
            new MockResponse('{"access_token":"long"}'),
            new MockResponse('{"data":[{"id":"11","access_token":"other-token"},{"id":"55","access_token":"page-token"}]}'),
            new MockResponse('{"id":"55"}'),
        ], pageId: '55')->connect('code', 'https://example.org/callback');

        $this->assertSame(['pageId' => '55', 'pageToken' => 'page-token', 'instagramId' => null], $connection);
    }

    public function testAPageTheAccountDoesNotManageCannotBeConnected(): void
    {
        $this->expectExceptionMessage('does not manage the Page "99"');

        $this->client([new MockResponse('{"access_token":"s"}'), new MockResponse('{"access_token":"l"}'), new MockResponse('{"data":[{"id":"55","access_token":"t"}]}')], pageId: '99')->connect('code', 'https://example.org/callback');
    }

    public function testAnAccountManagingNoPageCannotBeConnected(): void
    {
        $this->expectExceptionMessage('manages no Page');

        $this->client([new MockResponse('{"access_token":"s"}'), new MockResponse('{"access_token":"l"}'), new MockResponse('{"data":[]}')])->connect('code', 'https://example.org/callback');
    }

    public function testARefusalThrowsWithMetasMessage(): void
    {
        $this->expectExceptionMessage('Invalid OAuth access token');

        $this->client([new MockResponse('{"error":{"message":"Invalid OAuth access token"}}', ['http_code' => 400])])->request('GET', '/me', []);
    }

    public function testThePageTokenIsNullBeforeTheConnection(): void
    {
        $this->assertNull($this->client()->pageToken());
        $this->assertSame('token', $this->client(pageToken: 'token')->pageToken());
    }
}
