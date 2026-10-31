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
use Symfony\Contracts\HttpClient\HttpClientInterface;

// Everything that talks to Pinterest: the connection of the business account the pins go out on, the board they are pinned to, then the calls PinterestPublisher makes - one stubable seam, same shape as LinkedInClient. Pinterest hands a refresh token: the access token lasts 30 days, the refresh token 60, and each refresh hands both anew (asked as "continuous_refresh"), so the nightly c975l:social:pinterest:refresh keeps the connection alive for as long as it runs
class PinterestClient
{
    public const string AUTHORIZE = 'https://www.pinterest.com/oauth/';

    public const string API = 'https://api.pinterest.com';

    // Where an app with Trial access has to pin, its boards, pins and tokens kept apart from the account's real ones - a production token being refused there, and the other way round
    public const string SANDBOX_API = 'https://api-sandbox.pinterest.com';

    // The board created in the sandbox, which starts empty
    public const string SANDBOX_BOARD = 'Sandbox';

    // Reading the boards and the account, and pinning - what POST /v5/pins asks for
    public const string SCOPES = 'boards:read,boards:write,pins:read,pins:write,user_accounts:read';

    // An access token this close to its end is refreshed by the nightly command, a call only refreshing one already over - two refreshes at once would spend the same refresh token
    public const string REFRESH_BEFORE = '+7 days';

    // What Pinterest's documentation gives, for a token exchange that would not say
    private const int ACCESS_LIFETIME = 2592000;

    private const int REFRESH_LIFETIME = 5184000;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
    ) {
    }

    // Whether the site holds its Pinterest app's credentials - the tokens being what the connection goes and fetches
    public function isConfigured(): bool
    {
        return '' !== $this->config('social-pinterest-client-id') && '' !== $this->config('social-pinterest-client-secret');
    }

    // Whether an account is connected with a refresh token still valid, the access token renewing itself from it
    public function isConnected(): bool
    {
        $expiresAt = $this->refreshExpiresAt();

        return '' !== $this->config('social-pinterest-refresh-token') && (null === $expiresAt || $expiresAt > new \DateTimeImmutable());
    }

    // Whether the calls go to the sandbox, until Pinterest grants the app its Standard access
    public function isSandbox(): bool
    {
        return \in_array($this->config('social-pinterest-sandbox'), ['1', 'true'], true);
    }

    // Whether a board is picked, a pin needing one
    public function hasBoard(): bool
    {
        return '' !== $this->config('social-pinterest-board-id');
    }

    // When the access token stops working, null before any connection
    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-pinterest-token-expires-at');
    }

    // When the refresh token stops working, past which the account has to connect again
    public function refreshExpiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-pinterest-refresh-token-expires-at');
    }

    // Pinterest's consent page
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config('social-pinterest-client-id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::SCOPES,
        ]);
    }

    // Trades the code the callback received for the tokens, then asks whose account it is - what "Connexions" names and links
    /** @return array<string, string> the configs to write */
    public function connect(string $code, string $redirectUri): array
    {
        $values = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $account = $this->request('GET', '/v5/user_account', null, $values['social-pinterest-access-token']);
        if (!isset($account['username']) || '' === (string) $account['username']) {
            throw new \RuntimeException('Pinterest did not answer the account name.');
        }

        return $values + ['social-pinterest-username' => (string) $account['username']];
    }

    // Trades the refresh token for new ones and stores them at once, the old refresh token being spent by the exchange
    public function refresh(): void
    {
        $refreshToken = $this->config('social-pinterest-refresh-token');
        if ('' === $refreshToken) {
            throw new \RuntimeException('No Pinterest account is connected.');
        }

        $this->configValueWriter->write($this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken, 'continuous_refresh' => 'true']));
    }

    // Whether the access token ends within REFRESH_BEFORE, or is gone
    public function needsRefresh(): bool
    {
        $expiresAt = $this->expiresAt();

        return null === $expiresAt || $expiresAt <= new \DateTimeImmutable(self::REFRESH_BEFORE);
    }

    // The account's boards, each with its name, in Pinterest's order - the secret ones left out, a pin there reaching no one
    /** @return list<array{id: string, name: string}> */
    public function boards(): array
    {
        $boards = [];
        $bookmark = null;
        do {
            $page = $this->request('GET', '/v5/boards?' . http_build_query(array_filter(['page_size' => 100, 'privacy' => 'PUBLIC', 'bookmark' => $bookmark])), null);
            foreach (\is_array($page['items'] ?? null) ? $page['items'] : [] as $board) {
                if (\is_array($board) && isset($board['id'])) {
                    $boards[] = ['id' => (string) $board['id'], 'name' => (string) ($board['name'] ?? $board['id'])];
                }
            }
            $bookmark = \is_string($page['bookmark'] ?? null) && '' !== $page['bookmark'] ? $page['bookmark'] : null;
        } while (null !== $bookmark);

        // The sandbox starts without any board: one is created there, the picker then taking it
        if ([] === $boards && $this->isSandbox()) {
            $board = $this->request('POST', '/v5/boards', ['name' => self::SANDBOX_BOARD, 'privacy' => 'PUBLIC']);
            if (isset($board['id'])) {
                $boards[] = ['id' => (string) $board['id'], 'name' => (string) ($board['name'] ?? self::SANDBOX_BOARD)];
            }
        }

        return $boards;
    }

    // Creates a pin on the board picked, answering its id
    /** @param array<string, mixed> $pin */
    public function createPin(array $pin): string
    {
        $created = $this->request('POST', '/v5/pins', ['board_id' => $this->config('social-pinterest-board-id')] + $pin);
        if (!isset($created['id'])) {
            throw new \RuntimeException('Pinterest did not answer the id of the pin.');
        }

        return (string) $created['id'];
    }

    // The configs a token exchange writes: both tokens and both ends, the refresh token's given as a timestamp or as a duration depending on the grant - and left as it was when a refresh hands none
    /**
     * @param array<string, string> $body
     *
     * @return array<string, string>
     */
    private function token(array $body): array
    {
        $response = $this->httpClient->request('POST', $this->api() . '/v5/oauth/token', [
            'auth_basic' => [$this->config('social-pinterest-client-id'), $this->config('social-pinterest-client-secret')],
            'body' => $body,
            'timeout' => 30,
        ]);
        // Decoded rather than toArray(): an HTML error page has to end as the refusal below, not as a JsonException no caller expects
        $token = json_decode($response->getContent(false), true);
        $token = \is_array($token) ? $token : [];
        if (200 !== $response->getStatusCode() || !isset($token['access_token'])) {
            throw new \RuntimeException('Pinterest refused the connection: ' . ($token['message'] ?? $token['error_description'] ?? $response->getStatusCode()));
        }

        $values = [
            'social-pinterest-access-token' => (string) $token['access_token'],
            'social-pinterest-token-expires-at' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? self::ACCESS_LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
        ];
        if (!isset($token['refresh_token'])) {
            return $values;
        }

        $refreshExpiresAt = isset($token['refresh_token_expires_at'])
            ? new \DateTimeImmutable('@' . (int) $token['refresh_token_expires_at'])
            : new \DateTimeImmutable('+' . (int) ($token['refresh_token_expires_in'] ?? self::REFRESH_LIFETIME) . ' seconds');

        return $values + [
            'social-pinterest-refresh-token' => (string) $token['refresh_token'],
            'social-pinterest-refresh-token-expires-at' => $refreshExpiresAt->format(\DateTimeInterface::ATOM),
        ];
    }

    // One API call, JSON both ways, the access token refreshed first when it is over
    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json, ?string $accessToken = null): array
    {
        $expiresAt = $this->expiresAt();
        if (null === $accessToken && (null === $expiresAt || $expiresAt <= new \DateTimeImmutable())) {
            $this->refresh();
        }

        $response = $this->httpClient->request($method, $this->api() . $path, [
            'auth_bearer' => $accessToken ?? $this->config('social-pinterest-access-token'),
            ...(null === $json ? [] : ['json' => $json]),
            'timeout' => 60,
        ]);

        // A refusal thrown with Pinterest's own explanation ("Board not found", a picture it cannot read)
        ApiRefusal::throwIf($response, 'Pinterest', (string) strtok($path, '?'));
        $data = json_decode($response->getContent(false), true);

        return \is_array($data) ? $data : [];
    }

    // The sandbox's host until Standard access, the production one after
    private function api(): string
    {
        return $this->isSandbox() ? self::SANDBOX_API : self::API;
    }

    private function date(string $slug): ?\DateTimeImmutable
    {
        $value = $this->config($slug);

        try {
            return '' === $value ? null : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }
}
