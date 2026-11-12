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

// The connection of an Instagram professional account by Instagram Login, with no Facebook Page, for an account Meta refuses to link to one (see MetaGraphClient for the other way). Same calls as the Page's on graph.instagram.com, through the same seam, with a token of the account's own: it lasts 60 days and only renews itself before that end, which the nightly c975l:social:instagram:refresh sees to. The app is the one Meta's dashboard names under "API setup with Instagram login", with its own id and secret, the account an Instagram tester of it: in development mode it needs no App Review
class InstagramLoginClient
{
    use ConfigValuesTrait;

    public const string AUTHORIZE = 'https://www.instagram.com/oauth/authorize';

    public const string OAUTH_HOST = 'https://api.instagram.com';

    public const string HOST = 'https://graph.instagram.com';

    public const string GRAPH = self::HOST . '/' . MetaGraphClient::VERSION;

    // Reading the account, which the renewal also asks for, publishing, and reading the comments the alert quotes
    public const string SCOPES = 'instagram_business_basic,instagram_business_content_publish,instagram_business_manage_comments';

    // A token this close to its end is renewed by the nightly command, Instagram refusing one less than a day old
    public const string REFRESH_BEFORE = '+30 days';

    // What Instagram's documentation gives, for an exchange that would not say
    private const int LIFETIME = 5184000;

    public function __construct(
        private readonly MetaGraphClient $metaGraphClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
    ) {
    }

    // Whether the site holds the app's credentials - the token being what the connection goes and fetches
    public function isConfigured(): bool
    {
        return '' !== $this->config('social-instagram-app-id') && '' !== $this->config('social-instagram-app-secret');
    }

    // Whether an account is connected with a token still valid, an ended one renewing no more
    public function isConnected(): bool
    {
        $expiresAt = $this->expiresAt();

        return '' !== $this->config('social-instagram-access-token') && '' !== $this->accountId() && (null === $expiresAt || $expiresAt > new \DateTimeImmutable());
    }

    public function accessToken(): string
    {
        return $this->config('social-instagram-access-token');
    }

    // The account's id, the one the posts' paths name
    public function accountId(): string
    {
        return $this->config('social-instagram-user-id');
    }

    // When the token stops working, null before any connection
    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-instagram-token-expires-at');
    }

    // Whether the token ends within REFRESH_BEFORE
    public function needsRefresh(): bool
    {
        $expiresAt = $this->expiresAt();

        return null === $expiresAt || $expiresAt <= new \DateTimeImmutable(self::REFRESH_BEFORE);
    }

    // Instagram's consent page, its own credentials asked even of a browser signed in, so the account connected is the one meant, and no Facebook login offered
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_id' => $this->config('social-instagram-app-id'),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'state' => $state,
            'enable_fb_login' => '0',
            'force_reauth' => 'true',
        ]);
    }

    // Trades the code the callback received for a short token then a long one, then asks whose account it is - the id the posts' paths name, and what "Connexions" names and links
    /** @return array<string, string> the configs to write */
    public function connect(string $code, string $redirectUri): array
    {
        $short = $this->metaGraphClient->requestAt(self::OAUTH_HOST, 'POST', '/oauth/access_token', [
            'client_id' => $this->config('social-instagram-app-id'),
            'client_secret' => $this->config('social-instagram-app-secret'),
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ]);
        // The documentation shows it wrapped in "data", some answers giving it bare
        $short = \is_array($short['data'][0] ?? null) ? $short['data'][0] : $short;
        $values = $this->token($this->metaGraphClient->requestAt(self::HOST, 'POST', '/access_token', [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->config('social-instagram-app-secret'),
            'access_token' => (string) ($short['access_token'] ?? ''),
        ]));

        $account = $this->request('GET', '/me', ['fields' => 'user_id,username', 'access_token' => $values['social-instagram-access-token']]);
        if (!isset($account['user_id'], $account['username'])) {
            throw new \RuntimeException('Instagram did not answer the account.');
        }

        return $values + ['social-instagram-user-id' => (string) $account['user_id'], 'social-instagram-username' => (string) $account['username']];
    }

    // Trades the token for a new one and stores it at once
    public function refresh(): void
    {
        if ('' === $this->accessToken()) {
            throw new \RuntimeException('No Instagram account is connected.');
        }

        $this->configValueWriter->write($this->token($this->metaGraphClient->requestAt(self::HOST, 'GET', '/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $this->accessToken()])));
    }

    // One call, the same as MetaGraphClient::request() on Instagram's own host
    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $parameters): array
    {
        return $this->metaGraphClient->requestAt(self::GRAPH, $method, $path, $parameters);
    }

    // The configs an exchange writes: the token and its end
    /**
     * @param array<string, mixed> $token
     *
     * @return array<string, string>
     */
    private function token(array $token): array
    {
        if (!isset($token['access_token'])) {
            throw new \RuntimeException('Instagram did not answer the token.');
        }

        return [
            'social-instagram-access-token' => (string) $token['access_token'],
            'social-instagram-token-expires-at' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? self::LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
        ];
    }
}
