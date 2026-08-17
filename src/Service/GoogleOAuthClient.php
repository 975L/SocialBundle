<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The OAuth half of the Google Business Profile connection, kept apart from GoogleBusinessProfileSource so what talks to Google's token endpoint is one stubable seam - same shape as ConfigBundle's own AiCrawlerListClient
class GoogleOAuthClient
{
    public const string AUTHORIZATION_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    public const string TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    // The one scope the reviews endpoints need, and a sensitive one: an app requesting it has to be published before its refresh tokens stop expiring every seven days
    public const string SCOPE = 'https://www.googleapis.com/auth/business.manage';

    // Google issues access tokens for an hour; kept a little short so a request never leaves with one about to expire
    private const int ACCESS_TOKEN_TTL = 3300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly CacheInterface $cache,
    ) {
    }

    // Whether the site holds the credentials a connection needs, the refresh token aside - which is precisely what the connection goes and fetches
    public function isConfigured(): bool
    {
        return null !== $this->clientId() && null !== $this->clientSecret();
    }

    // Where the owner is sent to consent. "consent" is forced because Google only returns a refresh token on the first authorization otherwise, leaving a re-connection with nothing to store
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZATION_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    // Trades the code the callback received for the long-lived refresh token, the only part worth storing
    public function exchangeCode(string $code, string $redirectUri): string
    {
        $token = $this->requestToken([
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if (!isset($token['refresh_token']) || !is_string($token['refresh_token'])) {
            throw new \RuntimeException('Google returned no refresh token: the authorization was not granted offline access.');
        }

        return $token['refresh_token'];
    }

    // A short-lived access token, cached for its own lifetime rather than requested per call - a sync paging through reviews would otherwise ask for one on every page
    public function getAccessToken(): string
    {
        $refreshToken = $this->refreshToken();

        if (null === $refreshToken) {
            throw new \RuntimeException('No Google refresh token stored: connect the site to Google first.');
        }

        return $this->cache->get(
            'social_google_access_token_' . hash('xxh128', $refreshToken),
            function (ItemInterface $item) use ($refreshToken): string {
                $item->expiresAfter(self::ACCESS_TOKEN_TTL);
                $token = $this->requestToken([
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);

                if (!isset($token['access_token']) || !is_string($token['access_token'])) {
                    throw new \RuntimeException('Google returned no access token for the stored refresh token.');
                }

                return $token['access_token'];
            }
        );
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    private function requestToken(array $parameters): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
            'body' => [
                ...$parameters,
                'client_id' => (string) $this->clientId(),
                'client_secret' => (string) $this->clientSecret(),
            ],
            'timeout' => 15,
        ]);

        // Read before the status is judged: Google states the reason ("invalid_grant" on a revoked token) in the body, which an exception on the status alone would throw away
        $token = json_decode($response->getContent(false), true);

        if ($response->getStatusCode() >= 400 || !is_array($token)) {
            throw new \RuntimeException(sprintf('Google refused the token request (%d): %s', $response->getStatusCode(), $response->getContent(false)));
        }

        return $token;
    }

    private function clientId(): ?string
    {
        $value = $this->configService->get('social-google-oauth-client-id');

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function clientSecret(): ?string
    {
        $value = $this->configService->get('social-google-oauth-client-secret');

        return is_string($value) && '' !== $value ? $value : null;
    }

    private function refreshToken(): ?string
    {
        $value = $this->configService->get('social-google-oauth-refresh-token');

        return is_string($value) && '' !== $value ? $value : null;
    }
}
