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

    // The headers of the last request, lowercased
    /** @var list<string> */
    private array $headers = [];

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses = [], ?string $pageToken = null): MetaGraphClient
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-meta-app-id', '123'],
            ['social-meta-app-secret', 'secret'],
            ['social-meta-page-token', $pageToken],
        ]);

        return new MetaGraphClient(new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->urls[] = $url;
            $this->headers = array_map(strtolower(...), $options['headers'] ?? []);

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

    // Code, then a long-lived user token, then every Page - up to 100, past Meta's default of 25 - with its token - one that does not expire - and the Instagram account linked to it, in one call
    public function testConnectTradesTheCodeForThePagesTheirTokensAndInstagramAccounts(): void
    {
        $pages = $this->client([
            new MockResponse('{"access_token":"short"}'),
            new MockResponse('{"access_token":"long"}'),
            new MockResponse('{"data":[{"id":"55","name":"Papa Câlin","access_token":"page-token","instagram_business_account":{"id":"77","username":"papacalin"}},{"id":"11","name":"Other","access_token":"other-token"}]}'),
        ])->connect('code', 'https://example.org/callback');

        $this->assertSame([
            ['pageId' => '55', 'name' => 'Papa Câlin', 'pageToken' => 'page-token', 'instagramId' => '77', 'instagram' => 'papacalin'],
            ['pageId' => '11', 'name' => 'Other', 'pageToken' => 'other-token', 'instagramId' => null, 'instagram' => null],
        ], $pages);
        $this->assertStringContainsString('fb_exchange_token=short', $this->urls[1]);
        $this->assertStringContainsString('instagram_business_account', urldecode($this->urls[2]));
        $this->assertStringContainsString('limit=100', $this->urls[2]);
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

    // A reel's video sent at the address its first phase gave out, outside the Graph host, its size said in the headers
    public function testAVideoIsSentAtTheUploadAddress(): void
    {
        $video = (string) tempnam(sys_get_temp_dir(), 'reel');
        file_put_contents($video, str_repeat('v', 2048));

        $this->client([new MockResponse('{"success": true}')])->uploadFile('https://rupload.example/r1', $video, 'page-token');
        unlink($video);

        $this->assertSame(['https://rupload.example/r1'], $this->urls);
        $this->assertContains('file_size: 2048', $this->headers);
        $this->assertContains('content-length: 2048', $this->headers);
    }

    // A refusal carries Meta's own reason
    public function testARefusedUploadSaysWhy(): void
    {
        $this->expectExceptionMessageIsOrContains('Meta refused the reel upload: Invalid file');

        $this->client([new MockResponse('{"success": false, "debug_info": {"message": "Invalid file"}}')])->uploadFile('https://rupload.example/r1', __FILE__, 'page-token');
    }
}
