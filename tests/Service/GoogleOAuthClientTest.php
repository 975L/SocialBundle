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
use c975L\SocialBundle\Service\GoogleOAuthClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;

class GoogleOAuthClientTest extends TestCase
{
    private function createConfigService(?string $clientId = 'client-id', ?string $clientSecret = 'client-secret', ?string $refreshToken = 'refresh-token'): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([
            ['social-google-oauth-client-id', $clientId],
            ['social-google-oauth-client-secret', $clientSecret],
            ['social-google-oauth-refresh-token', $refreshToken],
        ]);

        return $configService;
    }

    private function createClient(MockHttpClient $httpClient, ?string $clientId = 'client-id', ?string $refreshToken = 'refresh-token', ?CacheInterface $cache = null): GoogleOAuthClient
    {
        return new GoogleOAuthClient(
            $httpClient,
            $this->createConfigService($clientId, 'client-secret', $refreshToken),
            $cache ?? new ArrayAdapter(),
        );
    }

    // The refresh token is left out on purpose: it is what the connection goes and fetches, so requiring it here would make the connection impossible to start
    public function testIsConfiguredOnlyNeedsTheClientCredentials(): void
    {
        $client = $this->createClient(new MockHttpClient(), refreshToken: null);

        $this->assertTrue($client->isConfigured());
    }

    public function testIsConfiguredIsFalseWithoutAClientId(): void
    {
        $client = $this->createClient(new MockHttpClient(), clientId: null);

        $this->assertFalse($client->isConfigured());
    }

    // Without "access_type=offline" and "prompt=consent" Google returns a refresh token on the first authorization only, leaving a re-connection with nothing to store
    public function testGetAuthorizationUrlAsksForOfflineAccessAndForcesTheConsentScreen(): void
    {
        $url = $this->createClient(new MockHttpClient())->getAuthorizationUrl('https://example.org/callback', 'state-value');

        $this->assertStringStartsWith(GoogleOAuthClient::AUTHORIZATION_ENDPOINT . '?', $url);

        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertSame('state-value', $query['state']);
        $this->assertSame(GoogleOAuthClient::SCOPE, $query['scope']);
        $this->assertSame('https://example.org/callback', $query['redirect_uri']);
    }

    public function testExchangeCodeReturnsTheRefreshToken(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"refresh_token":"stored-token","access_token":"short-lived"}')]);

        $token = $this->createClient($httpClient)->exchangeCode('auth-code', 'https://example.org/callback');

        $this->assertSame('stored-token', $token);
    }

    // An authorization granted without offline access answers with an access token alone, which would leave the site connected for an hour and then silently stop
    public function testExchangeCodeThrowsWhenGoogleReturnsNoRefreshToken(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"access_token":"short-lived"}')]);

        $this->expectException(\RuntimeException::class);

        $this->createClient($httpClient)->exchangeCode('auth-code', 'https://example.org/callback');
    }

    // Google states the reason in the body ("invalid_grant" on a revoked token), so the message has to carry it rather than the status alone
    public function testExchangeCodeThrowsWithGooglesOwnMessageOnAnErrorAnswer(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/invalid_grant/');

        $this->createClient($httpClient)->exchangeCode('auth-code', 'https://example.org/callback');
    }

    public function testGetAccessTokenMintsOneFromTheStoredRefreshToken(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{"access_token":"minted"}')]);

        $this->assertSame('minted', $this->createClient($httpClient)->getAccessToken());
    }

    // A sync paging through reviews would otherwise ask Google for a token on every page
    public function testGetAccessTokenIsCachedAcrossCalls(): void
    {
        $calls = 0;
        $httpClient = new MockHttpClient(function () use (&$calls): MockResponse {
            ++$calls;

            return new MockResponse('{"access_token":"minted"}');
        });
        $client = $this->createClient($httpClient);

        $client->getAccessToken();
        $client->getAccessToken();

        $this->assertSame(1, $calls);
    }

    public function testGetAccessTokenThrowsWhenTheSiteWasNeverConnected(): void
    {
        $client = $this->createClient(new MockHttpClient(), refreshToken: null);

        $this->expectException(\RuntimeException::class);

        $client->getAccessToken();
    }

    public function testGetAccessTokenThrowsWhenGoogleReturnsNoAccessToken(): void
    {
        $httpClient = new MockHttpClient([new MockResponse('{}')]);

        $this->expectException(\RuntimeException::class);

        $this->createClient($httpClient)->getAccessToken();
    }
}
