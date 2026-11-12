<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\InstagramLoginClient;
use c975L\SocialBundle\Service\MetaGraphClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

class InstagramLoginClientTest extends TestCase
{
    use ClientTestTrait;

    /**
     * @param list<MockResponse>    $responses
     * @param array<string, string> $config
     */
    private function client(array $responses = [], array $config = []): InstagramLoginClient
    {
        $config += [
            'social-instagram-app-id' => 'id',
            'social-instagram-app-secret' => 'secret',
            'social-instagram-access-token' => 'ig-token',
            'social-instagram-token-expires-at' => new \DateTimeImmutable('+50 days')->format(\DateTimeInterface::ATOM),
            'social-instagram-user-id' => '77',
        ];
        $configService = $this->configService($config);

        return new InstagramLoginClient(new MetaGraphClient($this->httpClient($responses), $configService), $configService, $this->configValueWriter());
    }

    // The consent asks what posting needs, of the account's own credentials and with no Facebook login
    public function testTheConsentAsksTheScopesOfPosting(): void
    {
        $url = $this->client()->getAuthorizationUrl('https://example.org/social/instagram/callback', 'state');
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

        $this->assertStringStartsWith(InstagramLoginClient::AUTHORIZE, $url);
        $this->assertSame(['id', 'https://example.org/social/instagram/callback', 'code', InstagramLoginClient::SCOPES, 'state', '0', 'true'], [$query['client_id'], $query['redirect_uri'], $query['response_type'], $query['scope'], $query['state'], $query['enable_fb_login'], $query['force_reauth']]);
    }

    // The code traded for a short token, that one for a long one, then the account asked its id and name
    public function testTheConnectionKeepsTheLongTokenItsEndAndTheAccount(): void
    {
        $values = $this->client([
            new MockResponse('{"data": [{"access_token": "short", "user_id": "1020", "permissions": "instagram_business_basic"}]}'),
            new MockResponse('{"access_token": "long", "token_type": "bearer", "expires_in": 5183944}'),
            new MockResponse('{"user_id": "77", "username": "catalog", "id": "1020"}'),
        ])->connect('code', 'https://example.org/social/instagram/callback');

        $this->assertSame(['long', '77', 'catalog'], [$values['social-instagram-access-token'], $values['social-instagram-user-id'], $values['social-instagram-username']]);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+5183944 seconds')->getTimestamp(), new \DateTimeImmutable($values['social-instagram-token-expires-at'])->getTimestamp(), 5);
        $this->assertSame('https://api.instagram.com/oauth/access_token', $this->calls[0]['url']);
        $this->assertStringContainsString('grant_type=authorization_code', (string) $this->calls[0]['body']);
        $this->assertStringContainsString('client_secret=secret', (string) $this->calls[0]['body']);
        $this->assertSame(['POST', 'https://graph.instagram.com/access_token'], [$this->calls[1]['method'], $this->calls[1]['url']]);
        $this->assertStringContainsString('grant_type=ig_exchange_token', (string) $this->calls[1]['body']);
        $this->assertContains('Authorization: Bearer short', $this->calls[1]['headers']);
        $this->assertStringStartsWith(InstagramLoginClient::GRAPH . '/me?', $this->calls[2]['url']);
        $this->assertStringNotContainsString('access_token', $this->calls[2]['url']);
        $this->assertContains('Authorization: Bearer long', $this->calls[2]['headers']);
    }

    // The short token answered bare rather than wrapped in "data"
    public function testTheShortTokenIsReadBareToo(): void
    {
        $this->client([
            new MockResponse('{"access_token": "short"}'),
            new MockResponse('{"access_token": "long"}'),
            new MockResponse('{"user_id": "77", "username": "catalog"}'),
        ])->connect('code', 'https://example.org/callback');

        $this->assertContains('Authorization: Bearer short', $this->calls[1]['headers']);
    }

    // A refusal says Instagram's own reason, its message standing at a place of its own on the token host
    public function testARefusedConnectionSaysInstagramsReason(): void
    {
        $this->expectExceptionMessage('Meta refused /oauth/access_token: Invalid redirect_uri');

        $this->client([new MockResponse('{"error_type": "OAuthException", "code": 400, "error_message": "Invalid redirect_uri"}', ['http_code' => 400])])->connect('code', 'https://example.org/callback');
    }

    // An HTML error page ends as a refusal, not as a decoding error no caller expects
    public function testAnErrorPageIsARefusal(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Meta refused /access_token: 502');

        $this->client([new MockResponse('{"access_token": "short"}'), new MockResponse('<html></html>', ['http_code' => 502])])->connect('code', 'https://example.org/callback');
    }

    // An account answered without its id could not post: refused rather than stored half
    public function testAnAccountWithoutItsIdIsRefused(): void
    {
        $this->expectExceptionMessage('Instagram did not answer the account.');

        $this->client([new MockResponse('{"access_token": "short"}'), new MockResponse('{"access_token": "long"}'), new MockResponse('{"username": "catalog"}')])->connect('code', 'https://example.org/callback');
    }

    // The renewal trades the token for a new one, stored at once with its end
    public function testTheRefreshStoresTheNewToken(): void
    {
        $this->client([new MockResponse('{"access_token": "renewed", "expires_in": 5184000}')])->refresh();

        $this->assertStringStartsWith('https://graph.instagram.com/refresh_access_token?', $this->calls[0]['url']);
        $this->assertStringContainsString('grant_type=ig_refresh_token', $this->calls[0]['url']);
        $this->assertStringNotContainsString('ig-token', $this->calls[0]['url']);
        $this->assertContains('Authorization: Bearer ig-token', $this->calls[0]['headers']);
        $this->assertSame('renewed', $this->written[0]['social-instagram-access-token']);
        $this->assertEqualsWithDelta(new \DateTimeImmutable('+60 days')->getTimestamp(), new \DateTimeImmutable((string) $this->written[0]['social-instagram-token-expires-at'])->getTimestamp(), 5);
    }

    public function testARefreshWithoutATokenAsksNothing(): void
    {
        $this->expectExceptionMessage('No Instagram account is connected.');

        $this->client(config: ['social-instagram-access-token' => ''])->refresh();
    }

    // Connected while the token lasts: an ended one renews no more, and the Page's account takes over
    public function testTheConnectionEndsWithItsToken(): void
    {
        $this->assertTrue($this->client()->isConnected());
        $this->assertFalse($this->client(config: ['social-instagram-access-token' => ''])->isConnected());
        $this->assertFalse($this->client(config: ['social-instagram-user-id' => ''])->isConnected());
        $this->assertFalse($this->client(config: ['social-instagram-token-expires-at' => new \DateTimeImmutable('-1 day')->format(\DateTimeInterface::ATOM)])->isConnected());
    }

    // Renewed from a month before its end only, Instagram refusing a token less than a day old
    public function testARenewalIsDueAMonthBeforeTheEnd(): void
    {
        $this->assertFalse($this->client()->needsRefresh());
        $this->assertTrue($this->client(config: ['social-instagram-token-expires-at' => new \DateTimeImmutable('+29 days')->format(\DateTimeInterface::ATOM)])->needsRefresh());
    }

    public function testTheAppKeysAreWhatMakesItConfigured(): void
    {
        $this->assertTrue($this->client()->isConfigured());
        $this->assertFalse($this->client(config: ['social-instagram-app-secret' => ''])->isConfigured());
    }
}
