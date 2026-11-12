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
use Symfony\Contracts\HttpClient\ResponseInterface;

// Everything that talks to LinkedIn: the connection of the member whose profile, or whose Page, the posts go out on, then the calls LinkedInPublisher makes with the token it stores - one stubable seam, same shape as MetaGraphClient. A profile is reached through an app with the two self-serve products "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect", a Page through an app with the "Community Management API" alone, which LinkedIn wants as the only product of its app. The token lasts 60 days: a Page app is handed a refresh token, a year long, that c975l:social:linkedin:refresh renews it from; a profile app none, its member connecting again
class LinkedInClient
{
    use ConfigValuesTrait;

    public const string AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';

    public const string TOKEN = 'https://www.linkedin.com/oauth/v2/accessToken';

    public const string API = 'https://api.linkedin.com';

    // The version every call to the versioned API is pinned to, 202609 being the latest in October 2026 - each one lives a year at least, so it is bumped here, once a year (see learn.microsoft.com/linkedin/marketing/versioning)
    public const string VERSION = '202609';

    // Posting as the member, and reading who the member is
    public const string SCOPES = 'openid profile w_member_social';

    // Posting as a Page the member administers, and reading who the member is and which Pages they administer - an app of the Community Management API having no OpenID Connect to ask "openid profile" of
    public const string PAGE_SCOPES = 'r_basicprofile w_member_social w_organization_social rw_organization_admin';

    // An access token this close to its end is renewed by the nightly command - ahead of the first reminder, which only goes when the renewal did not work
    public const string REFRESH_BEFORE = '+21 days';

    // What the tokens last when LinkedIn does not say
    private const int ACCESS_LIFETIME = 5184000;

    private const int REFRESH_LIFETIME = 31536000;

    // LinkedIn processes an uploaded video before a post may carry it: asked again this many times, this many seconds apart - about five minutes in 30 calls, the development tier of the Community Management API counting every one of them in its daily quota
    private const int VIDEO_STATUS_ATTEMPTS = 30;

    // A post just created is not always found at once by the comments API, nor to be shared again
    private const int FOUND_ATTEMPTS = 5;

    // The seconds between two of them
    private const int FOUND_DELAY = 3;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly int $statusDelay = 10,
    ) {
    }

    // Whether the site holds its LinkedIn app's credentials - the token being what the connection goes and fetches
    public function isConfigured(): bool
    {
        return '' !== $this->config('social-linkedin-client-id') && '' !== $this->config('social-linkedin-client-secret');
    }

    // Whether a member is connected with a token still valid
    public function isConnected(): bool
    {
        $expiresAt = $this->expiresAt();

        return null !== $this->accessToken() && '' !== $this->memberId() && (null === $expiresAt || $expiresAt > new \DateTimeImmutable());
    }

    // When the stored token stops working, null before any connection
    public function expiresAt(): ?\DateTimeImmutable
    {
        $value = $this->config('social-linkedin-token-expires-at');

        try {
            return '' === $value ? null : new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    // When the refresh token stops working, null when there is none
    public function refreshExpiresAt(): ?\DateTimeImmutable
    {
        return $this->date('social-linkedin-refresh-token-expires-at');
    }

    // Whether a refresh token is held and still valid - a profile app never having one
    public function canRefresh(): bool
    {
        $refreshExpiresAt = $this->refreshExpiresAt();

        return '' !== $this->config('social-linkedin-refresh-token') && (null === $refreshExpiresAt || $refreshExpiresAt > new \DateTimeImmutable());
    }

    // Whether the access token ends within REFRESH_BEFORE, or is gone
    public function needsRefresh(): bool
    {
        $expiresAt = $this->expiresAt();

        return null === $expiresAt || $expiresAt <= new \DateTimeImmutable(self::REFRESH_BEFORE);
    }

    // Trades the refresh token for a new access token and stores it at once. The refresh token's end does not move: past it the member consents again, the last access token ending with it for the reminders to say so
    public function refresh(): void
    {
        $response = $this->httpClient->request('POST', self::TOKEN, [
            'body' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $this->config('social-linkedin-refresh-token'),
                'client_id' => $this->config('social-linkedin-client-id'),
                'client_secret' => $this->config('social-linkedin-client-secret'),
            ],
            'timeout' => 30,
        ]);

        // Decoded rather than toArray(): an HTML error page has to end as the refusal below, not as a JsonException no caller expects
        $token = json_decode($response->getContent(false), true);
        $token = \is_array($token) ? $token : [];
        if (200 !== $response->getStatusCode() || !isset($token['access_token'])) {
            throw new \RuntimeException('LinkedIn refused the refresh: ' . ($token['error_description'] ?? $response->getStatusCode()));
        }

        $refresh = $this->refreshValues($token);
        $this->configValueWriter->write([
            'social-linkedin-access-token' => (string) $token['access_token'],
            'social-linkedin-token-expires-at' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? self::ACCESS_LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
        ] + ([] === $refresh ? [] : ['social-linkedin-refresh-token' => $refresh['refreshToken'], 'social-linkedin-refresh-token-expires-at' => $refresh['refreshExpiresAt']]));
    }

    // The refresh token of a token answer and its end as a string, what the picker's session holds too - nothing when LinkedIn hands none
    /**
     * @param array<mixed> $token
     *
     * @return array{refreshToken: string, refreshExpiresAt: string}|array{}
     */
    private function refreshValues(array $token): array
    {
        if (!isset($token['refresh_token'])) {
            return [];
        }

        return [
            'refreshToken' => (string) $token['refresh_token'],
            'refreshExpiresAt' => new \DateTimeImmutable('+' . (int) ($token['refresh_token_expires_in'] ?? self::REFRESH_LIFETIME) . ' seconds')->format(\DateTimeInterface::ATOM),
        ];
    }

    // Whether the posts go out on a Page rather than on the member's profile
    public function isPage(): bool
    {
        return '' !== $this->config('social-linkedin-organization-id');
    }

    // The Page the posts go out as, "urn:li:organization:<id>", once one is connected - the member otherwise, "urn:li:person:<id>"
    public function author(): string
    {
        return $this->isPage() ? 'urn:li:organization:' . $this->config('social-linkedin-organization-id') : 'urn:li:person:' . $this->memberId();
    }

    // LinkedIn's consent page, asking what a profile or a Page needs
    public function getAuthorizationUrl(string $redirectUri, string $state, bool $page = false): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config('social-linkedin-client-id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => $page ? self::PAGE_SCOPES : self::SCOPES,
        ]);
    }

    // Trades the code the callback received for the token, then asks who the member is - what the posts are signed with - and, for a Page, which Pages they administer, none being for the caller to refuse
    /** @return array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, name: string, organizations: list<array{id: string, name: string}>, refreshToken?: string, refreshExpiresAt?: string} */
    public function connect(string $code, string $redirectUri, bool $page = false): array
    {
        $response = $this->httpClient->request('POST', self::TOKEN, [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $redirectUri,
                'client_id' => $this->config('social-linkedin-client-id'),
                'client_secret' => $this->config('social-linkedin-client-secret'),
            ],
            'timeout' => 30,
        ]);
        $token = $response->toArray(false);
        if (200 !== $response->getStatusCode() || !isset($token['access_token'])) {
            throw new \RuntimeException('LinkedIn refused the connection: ' . ($token['error_description'] ?? $response->getStatusCode()));
        }

        $accessToken = (string) $token['access_token'];
        [$memberId, $name] = $page ? $this->pageMember($accessToken) : $this->profileMember($accessToken);

        return [
            'accessToken' => $accessToken,
            'memberId' => $memberId,
            'expiresAt' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? self::ACCESS_LIFETIME) . ' seconds'),
            'name' => $name,
            'organizations' => $page ? $this->organizations($accessToken) : [],
            ...$this->refreshValues($token),
        ];
    }

    // The member's id and name through OpenID Connect, the profile app's
    /** @return array{0: string, 1: string} */
    private function profileMember(string $accessToken): array
    {
        $member = $this->request('GET', '/v2/userinfo', null, $accessToken);
        if (!isset($member['sub'])) {
            throw new \RuntimeException('LinkedIn did not say which member is connected.');
        }

        return [(string) $member['sub'], (string) ($member['name'] ?? $member['sub'])];
    }

    // The member's id and name through /v2/me, the one r_basicprofile opens to a Page app - its name read when LinkedIn gives it, the id otherwise
    /** @return array{0: string, 1: string} */
    private function pageMember(string $accessToken): array
    {
        $member = $this->request('GET', '/v2/me', null, $accessToken);
        if (!isset($member['id'])) {
            throw new \RuntimeException('LinkedIn did not say which member is connected.');
        }

        $name = trim((string) ($member['localizedFirstName'] ?? '') . ' ' . (string) ($member['localizedLastName'] ?? ''));

        return [(string) $member['id'], '' === $name ? (string) $member['id'] : $name];
    }

    // The Pages the member administers or posts for as a content administrator, each with its name - LinkedIn's examples giving the Page as "organization" or as "organizationTarget", both read
    /** @return list<array{id: string, name: string}> */
    private function organizations(string $accessToken): array
    {
        $acls = $this->request('GET', '/rest/organizationAcls?q=roleAssignee&state=APPROVED&count=100', null, $accessToken)['elements'] ?? [];
        $ids = [];
        foreach (\is_array($acls) ? $acls : [] as $acl) {
            $urn = \is_array($acl) && \in_array($acl['role'] ?? null, ['ADMINISTRATOR', 'CONTENT_ADMINISTRATOR'], true) ? (string) ($acl['organization'] ?? $acl['organizationTarget'] ?? '') : '';
            if (str_starts_with($urn, 'urn:li:organization:')) {
                $ids[] = substr($urn, \strlen('urn:li:organization:'));
            }
        }

        $ids = array_values(array_unique($ids));
        $names = [] === $ids ? [] : $this->organizationNames($ids, $accessToken);

        return array_map(static fn (string $id): array => ['id' => $id, 'name' => $names[$id] ?? $id], $ids);
    }

    // The Pages' names in one call, a Page LinkedIn will not name left out for its id to stand in: the name is only what the screens show, never worth failing the connection
    /**
     * @param list<string> $ids
     *
     * @return array<string, string>
     */
    private function organizationNames(array $ids, string $accessToken): array
    {
        try {
            $results = $this->request('GET', '/rest/organizations?ids=List(' . implode(',', array_map(rawurlencode(...), $ids)) . ')', null, $accessToken)['results'] ?? [];
        } catch (\Exception) {
            return [];
        }

        $names = [];
        foreach (\is_array($results) ? $results : [] as $id => $organization) {
            $name = \is_array($organization) ? trim((string) ($organization['localizedName'] ?? '')) : '';
            if ('' !== $name) {
                $names[(string) $id] = $name;
            }
        }

        return $names;
    }

    // The configs a connection writes, all at once: the token, the member - whom isConnected() reads, a Page connected or not - and its end, the refresh token emptied when none came so an earlier one cannot outlive it, plus the Page picked when there is one
    /**
     * @param array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, refreshToken?: string, refreshExpiresAt?: string} $connection
     * @param array{id: string, name: string}|null                                                                                          $organization
     *
     * @return array<string, string|null>
     */
    public static function connectionValues(array $connection, ?array $organization = null): array
    {
        $values = [
            'social-linkedin-access-token' => $connection['accessToken'],
            'social-linkedin-member-id' => $connection['memberId'],
            'social-linkedin-token-expires-at' => $connection['expiresAt']->format(\DateTimeInterface::ATOM),
            'social-linkedin-refresh-token' => $connection['refreshToken'] ?? null,
            'social-linkedin-refresh-token-expires-at' => $connection['refreshExpiresAt'] ?? null,
        ];

        return null === $organization ? $values : $values + ['social-linkedin-organization-id' => $organization['id'], 'social-linkedin-organization-name' => $organization['name']];
    }

    // The day a connection lasts until, as its flash says it: its refresh token's end when it renews itself, its token's otherwise
    /** @param array{expiresAt: \DateTimeImmutable, refreshExpiresAt?: string} $connection */
    public static function connectedUntil(array $connection): string
    {
        return (isset($connection['refreshExpiresAt']) ? new \DateTimeImmutable($connection['refreshExpiresAt']) : $connection['expiresAt'])->format('d/m/Y');
    }

    // Uploads an image for a post, answering the urn the post refers to it by
    public function uploadImage(string $bytes): string
    {
        $upload = $this->request('POST', '/rest/images?action=initializeUpload', ['initializeUploadRequest' => ['owner' => $this->author()]])['value'] ?? [];
        if (!\is_array($upload) || !isset($upload['uploadUrl'], $upload['image'])) {
            throw new \RuntimeException('LinkedIn gave no address to upload the image to.');
        }

        $this->put((string) $upload['uploadUrl'], $bytes, 'image');

        return (string) $upload['image'];
    }

    // Uploads a video for a post in the parts LinkedIn cuts it into (4 MB each), then finalizes it with the ETag of each part, answering its urn
    public function uploadVideo(string $path): string
    {
        $size = @filesize($path);
        if (false === $size) {
            throw new \RuntimeException(sprintf('The video "%s" cannot be read.', $path));
        }

        $upload = $this->request('POST', '/rest/videos?action=initializeUpload', ['initializeUploadRequest' => [
            'owner' => $this->author(),
            'fileSizeBytes' => $size,
            'uploadCaptions' => false,
            'uploadThumbnail' => false,
        ]])['value'] ?? [];
        if (!\is_array($upload) || !isset($upload['video'], $upload['uploadInstructions']) || !\is_array($upload['uploadInstructions'])) {
            throw new \RuntimeException('LinkedIn gave no address to upload the video to.');
        }

        // Each part put where LinkedIn said, its ETag being what proves it arrived
        $etags = [];
        foreach ($upload['uploadInstructions'] as $instruction) {
            $first = (int) $instruction['firstByte'];
            $bytes = file_get_contents($path, false, null, $first, (int) $instruction['lastByte'] - $first + 1);
            if (false === $bytes) {
                throw new \RuntimeException(sprintf('The video "%s" cannot be read.', $path));
            }

            $etag = $this->put((string) $instruction['uploadUrl'], $bytes, 'video')->getHeaders(false)['etag'][0] ?? null;
            if (null === $etag) {
                throw new \RuntimeException('LinkedIn did not acknowledge a part of the video.');
            }
            $etags[] = $etag;
        }

        $this->request('POST', '/rest/videos?action=finalizeUpload', ['finalizeUploadRequest' => [
            'video' => (string) $upload['video'],
            'uploadToken' => (string) ($upload['uploadToken'] ?? ''),
            'uploadedPartIds' => $etags,
        ]]);
        $this->waitAvailable((string) $upload['video']);

        return (string) $upload['video'];
    }

    // Asks LinkedIn until the video is processed, throwing when it failed or is still at it
    private function waitAvailable(string $urn): void
    {
        for ($attempt = 1; $attempt <= self::VIDEO_STATUS_ATTEMPTS; ++$attempt) {
            $status = (string) ($this->request('GET', '/rest/videos/' . rawurlencode($urn), null)['status'] ?? '');
            if ('AVAILABLE' === $status) {
                return;
            }
            if ('PROCESSING_FAILED' === $status) {
                throw new \RuntimeException('LinkedIn could not process the video.');
            }
            sleep($this->statusDelay);
        }

        throw new \RuntimeException('LinkedIn is still processing the video: publish it again in a few minutes.');
    }

    // Creates a post, answering its urn, which LinkedIn hands back in a header rather than in the body - '' when missing, the post accepted being published all the same, as for a reshare
    /** @param array<string, mixed> $post */
    public function createPost(array $post): string
    {
        return $this->send('POST', '/rest/posts', $post, (string) $this->accessToken())->getHeaders(false)['x-restli-id'][0] ?? '';
    }

    // Shares the post again as the member who connected the Page, on their own profile, $commentary above it - answering the reshare's urn. Asked again while LinkedIn does not find the post yet, the same as a comment
    public function createReshare(string $postUrn, string $commentary): string
    {
        $response = $this->whenFound(fn (): ResponseInterface => $this->send('POST', '/rest/posts', [
            'author' => 'urn:li:person:' . $this->memberId(),
            'commentary' => $commentary,
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
            'reshareContext' => ['parent' => $postUrn],
        ], (string) $this->accessToken()));

        // The reshare is out whether or not its id comes back, so a missing one must not have it sent again
        return $response->getHeaders(false)['x-restli-id'][0] ?? '';
    }

    // Comments the post as its author, the member or the Page, answering the comment's id. The text is plain, links written out. Through the unversioned /v2 endpoint: the versioned /rest one asks a partner permission (Community Management API) that w_member_social alone does not give - whether /v2 takes a Page as actor is to be seen once LinkedIn grants a Page app
    public function createComment(string $postUrn, string $text): string
    {
        $response = $this->whenFound(fn (): ResponseInterface => $this->send('POST', '/v2/socialActions/' . rawurlencode($postUrn) . '/comments', [
            'actor' => $this->author(),
            'object' => $postUrn,
            'message' => ['text' => $text],
        ], (string) $this->accessToken()));

        // The comment is out whether or not its id comes back, so a missing one must not have it sent again
        return $response->getHeaders(false)['x-restli-id'][0] ?? (string) (('' === $response->getContent(false) ? [] : $response->toArray(false))['id'] ?? '');
    }

    // A call about a post just created, asked again while LinkedIn does not find it yet
    /**
     * @template T
     *
     * @param callable(): T $call
     *
     * @return T
     */
    private function whenFound(callable $call): mixed
    {
        for ($attempt = 1;; ++$attempt) {
            try {
                return $call();
            } catch (\RuntimeException $exception) {
                // Only a post LinkedIn does not find yet is worth asking again, anything else would fail the same or send it twice
                if ($attempt >= self::FOUND_ATTEMPTS || 404 !== $exception->getCode()) {
                    throw $exception;
                }
                // A few seconds are enough for a post to be found, the longer delay being the video's
                sleep(min($this->statusDelay, self::FOUND_DELAY));
            }
        }
    }

    // One API call, JSON both ways - the versioned headers sent on /rest only, never on the unversioned /v2 endpoints
    /**
     * @param array<string, mixed>|null $json
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json, ?string $accessToken = null): array
    {
        $response = $this->send($method, $path, $json, $accessToken ?? (string) $this->accessToken());

        return '' === $response->getContent(false) ? [] : $response->toArray(false);
    }

    /** @param array<string, mixed>|null $json */
    private function send(string $method, string $path, ?array $json, string $accessToken): ResponseInterface
    {
        $headers = str_starts_with($path, '/rest/') ? ['LinkedIn-Version' => self::VERSION, 'X-Restli-Protocol-Version' => '2.0.0'] : [];
        $response = $this->httpClient->request($method, self::API . $path, [
            'auth_bearer' => $accessToken,
            'headers' => $headers,
            ...(null === $json ? [] : ['json' => $json]),
            'timeout' => 30,
        ]);

        // A refusal thrown with LinkedIn's own explanation ("Not enough permissions", an expired token)
        ApiRefusal::throwIf($response, 'LinkedIn', $path);

        return $response;
    }

    // Puts raw bytes at an upload address LinkedIn handed out, outside the versioned API
    private function put(string $url, string $bytes, string $what): ResponseInterface
    {
        $response = $this->httpClient->request('PUT', $url, [
            'auth_bearer' => (string) $this->accessToken(),
            'headers' => ['Content-Type' => 'application/octet-stream'],
            'body' => $bytes,
            'timeout' => 60,
        ]);
        if ($response->getStatusCode() >= 300) {
            throw new \RuntimeException(sprintf('LinkedIn refused the %s upload (%d).', $what, $response->getStatusCode()));
        }

        return $response;
    }

    private function accessToken(): ?string
    {
        return '' === $this->config('social-linkedin-access-token') ? null : $this->config('social-linkedin-access-token');
    }

    private function memberId(): string
    {
        return $this->config('social-linkedin-member-id');
    }
}
