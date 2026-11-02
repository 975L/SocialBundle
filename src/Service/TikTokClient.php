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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Service\ResetInterface;

// Everything that talks to TikTok: the connection of the account the videos go out on, what that account may post right now, then the post and its processing - one stubable seam, same shape as PinterestClient. TikTok hands a refresh token: the access token lasts 2 hours, the refresh token 180 days, and each refresh hands a new one and kills the old, so the nightly c975l:social:tiktok:refresh keeps the connection alive. A sandbox is no other host: its own client key and secret are the whole difference
class TikTokClient implements ResetInterface
{
    use ConfigValuesTrait;

    public const string AUTHORIZE = 'https://www.tiktok.com/v2/auth/authorize/';

    public const string API = 'https://open.tiktokapis.com';

    // Reading the account's name, and posting on it directly - nothing else, an unused scope delaying TikTok's audit
    public const string SCOPES = 'user.info.basic,video.publish';

    // What TikTok's documentation gives, for a token exchange that would not say
    private const int ACCESS_LIFETIME = 7200;

    private const int REFRESH_LIFETIME = 15552000;

    // An access token refreshed this many seconds before it ends, so it never runs out between the check and the call
    private const int EXPIRY_MARGIN = 60;

    // What every refresh takes (see refresh()), two processes spending the same refresh token ending the connection
    private const string REFRESH_LOCK = 'social_tiktok_refresh';

    // The account's settings once asked in this request, the post's screen and its check asking them together - forgotten between a worker's messages (see reset())
    /** @var array{nickname: string, privacy_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_duration: ?int}|null */
    private ?array $creatorInfo = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly LockFactory $lockFactory,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment = 'prod',
    ) {
    }

    // The account's settings asked again in the next request or message, a long-running worker not keeping them for good
    public function reset(): void
    {
        $this->creatorInfo = null;
    }

    // Whether the site holds its TikTok app's credentials - the tokens being what the connection goes and fetches
    public function isConfigured(): bool
    {
        return '' !== $this->config('social-tiktok-client-key') && '' !== $this->config('social-tiktok-client-secret');
    }

    // Whether an account is connected with a refresh token still valid, the access token renewing itself from it
    public function isConnected(): bool
    {
        $expiresAt = $this->refreshExpiresAt();

        return '' !== $this->config('social-tiktok-refresh-token') && (null === $expiresAt || $expiresAt > new \DateTimeImmutable());
    }

    // When the access token stops working, null before any connection
    public function expiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-tiktok-token-expires-at');
    }

    // When the refresh token stops working, past which the account has to connect again
    public function refreshExpiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-tiktok-refresh-token-expires-at');
    }

    // TikTok's consent page
    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'client_key' => $this->config('social-tiktok-client-key'),
            'response_type' => 'code',
            'scope' => self::SCOPES,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    // Trades the code the callback received for the tokens, then asks whose account it is - what "Connexions" names
    /** @return array<string, string> the configs to write */
    public function connect(string $code, string $redirectUri): array
    {
        $values = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri]);
        $user = $this->request('GET', '/v2/user/info/?fields=open_id,display_name', null, $values['social-tiktok-access-token']);
        $name = (string) ($user['user']['display_name'] ?? '');
        if ('' === $name) {
            throw new \RuntimeException('TikTok did not answer the account name.');
        }

        return $values + ['social-tiktok-display-name' => $name];
    }

    // Whether this environment may spend the refresh token: a database imported locally holds production's, the vault key being the same, and refreshing it there would end production's connection
    public function renewsHere(): bool
    {
        return 'prod' === $this->environment;
    }

    // Trades the refresh token for new ones and stores them at once, the old one dying with the exchange: under a lock, the config read again behind it for the token another process just stored, $onlyIfOver leaving alone an access token it just renewed
    public function refresh(bool $onlyIfOver = false): void
    {
        if (!$this->renewsHere()) {
            throw new \RuntimeException('The TikTok tokens are only renewed in production.');
        }

        $lock = $this->lockFactory->createLock(self::REFRESH_LOCK);
        $lock->acquire(true);
        try {
            $this->configService->invalidateCache();
            if ($onlyIfOver && !$this->isAccessOver()) {
                return;
            }

            $refreshToken = $this->config('social-tiktok-refresh-token');
            if ('' === $refreshToken) {
                throw new \RuntimeException('No TikTok account is connected.');
            }

            $this->configValueWriter->write($this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]));
        } finally {
            $lock->release();
        }
    }

    // What the account may post right now: its name, the privacy levels it offers, the interactions it turned off, the longest video it takes - asked live, as TikTok requires of the screen a post is approved on, and again by $fresh just before each post
    /** @return array{nickname: string, privacy_options: list<string>, comment_disabled: bool, duet_disabled: bool, stitch_disabled: bool, max_duration: ?int} */
    public function creatorInfo(bool $fresh = false): array
    {
        if (!$fresh && null !== $this->creatorInfo) {
            return $this->creatorInfo;
        }

        $info = $this->request('POST', '/v2/post/publish/creator_info/query/', []);

        return $this->creatorInfo = [
            'nickname' => (string) ($info['creator_nickname'] ?? $info['creator_username'] ?? ''),
            'privacy_options' => array_values(array_map(strval(...), \is_array($info['privacy_level_options'] ?? null) ? $info['privacy_level_options'] : [])),
            'comment_disabled' => (bool) ($info['comment_disabled'] ?? false),
            'duet_disabled' => (bool) ($info['duet_disabled'] ?? false),
            'stitch_disabled' => (bool) ($info['stitch_disabled'] ?? false),
            'max_duration' => isset($info['max_video_post_duration_sec']) ? (int) $info['max_video_post_duration_sec'] : null,
        ];
    }

    // Asks TikTok to fetch the video at $videoUrl, on a domain verified in the app, and post it - answering the id its processing is followed with
    /**
     * @param array<string, mixed> $postInfo
     */
    public function postVideo(array $postInfo, string $videoUrl): string
    {
        $data = $this->request('POST', '/v2/post/publish/video/init/', ['post_info' => $postInfo, 'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => $videoUrl]]);
        if (!isset($data['publish_id'])) {
            throw new \RuntimeException('TikTok did not answer the id of the post.');
        }

        return (string) $data['publish_id'];
    }

    // Where the post's processing stands, with TikTok's reason when it failed
    /** @return array{status: string, reason: ?string} */
    public function status(string $publishId): array
    {
        $data = $this->request('POST', '/v2/post/publish/status/fetch/', ['publish_id' => $publishId]);

        return ['status' => (string) ($data['status'] ?? ''), 'reason' => isset($data['fail_reason']) ? (string) $data['fail_reason'] : null];
    }

    // The configs a token exchange writes: both tokens and both ends, the account's id with them
    /**
     * @param array<string, string> $body
     *
     * @return array<string, string>
     */
    private function token(array $body): array
    {
        $response = $this->httpClient->request('POST', self::API . '/v2/oauth/token/', [
            'body' => ['client_key' => $this->config('social-tiktok-client-key'), 'client_secret' => $this->config('social-tiktok-client-secret')] + $body,
            'timeout' => 30,
        ]);
        // Decoded rather than toArray(): an HTML error page has to end as the refusal below, not as a JsonException no caller expects
        $token = json_decode($response->getContent(false), true);
        $token = \is_array($token) ? $token : [];
        if (200 !== $response->getStatusCode() || !isset($token['access_token'], $token['refresh_token'])) {
            throw new \RuntimeException('TikTok refused the connection: ' . ($token['error_description'] ?? $token['error'] ?? $response->getStatusCode()));
        }

        return array_filter([
            'social-tiktok-access-token' => (string) $token['access_token'],
            'social-tiktok-token-expires-at' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? self::ACCESS_LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
            'social-tiktok-refresh-token' => (string) $token['refresh_token'],
            'social-tiktok-refresh-token-expires-at' => new \DateTimeImmutable('+' . (int) ($token['refresh_expires_in'] ?? self::REFRESH_LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
            'social-tiktok-open-id' => (string) ($token['open_id'] ?? ''),
        ], static fn (string $value): bool => '' !== $value);
    }

    // Whether the access token is over, or about to be
    private function isAccessOver(): bool
    {
        $expiresAt = $this->expiresAt();

        return null === $expiresAt || $expiresAt <= new \DateTimeImmutable('+' . self::EXPIRY_MARGIN . ' seconds');
    }

    // One API call, JSON both ways, the access token refreshed first when it is over. TikTok may refuse with a 200, its "error.code" then saying so rather than the status line
    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed> the answer's "data"
     */
    private function request(string $method, string $path, ?array $json, ?string $accessToken = null): array
    {
        if (null === $accessToken && $this->isAccessOver()) {
            $this->refresh(true);
        }

        $response = $this->httpClient->request($method, self::API . $path, [
            'auth_bearer' => $accessToken ?? $this->config('social-tiktok-access-token'),
            ...(null === $json ? [] : ['json' => (object) $json]),
            'timeout' => 60,
        ]);

        $body = json_decode($response->getContent(false), true);
        $body = \is_array($body) ? $body : [];
        $error = \is_array($body['error'] ?? null) ? $body['error'] : [];
        $code = (string) ($error['code'] ?? 'ok');
        if ($response->getStatusCode() >= 300 || 'ok' !== $code) {
            // The code first, the one that says what to do ("unaudited_client_can_only_post_to_private_accounts"), TikTok's own words after it
            $message = trim($code . ('' === (string) ($error['message'] ?? '') ? '' : ' - ' . $error['message']));
            throw new \RuntimeException(sprintf('TikTok refused %s: %s', strtok($path, '?'), 'ok' === $message ? (string) $response->getStatusCode() : $message), $response->getStatusCode());
        }

        return \is_array($body['data'] ?? null) ? $body['data'] : [];
    }
}
