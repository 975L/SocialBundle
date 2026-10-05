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

// Everything that talks to LinkedIn: the connection of the member whose profile the posts go out on, then the calls LinkedInPublisher makes with the token it stores - one stubable seam, same shape as MetaGraphClient. The app is the member's own, with the two self-serve products "Share on LinkedIn" and "Sign In with LinkedIn using OpenID Connect": no review, but no refresh token either, so the token lasts its 60 days and the member connects again
class LinkedInClient
{
    public const string AUTHORIZE = 'https://www.linkedin.com/oauth/v2/authorization';

    public const string TOKEN = 'https://www.linkedin.com/oauth/v2/accessToken';

    public const string API = 'https://api.linkedin.com';

    // The version every call to the versioned API is pinned to, 202609 being the latest in October 2026 - each one lives a year at least, so it is bumped here, once a year (see learn.microsoft.com/linkedin/marketing/versioning)
    public const string VERSION = '202609';

    // Posting as the member, and reading who the member is
    public const string SCOPES = 'openid profile w_member_social';

    // LinkedIn processes an uploaded video before a post may carry it: asked again this many times, this many seconds apart
    private const int VIDEO_STATUS_ATTEMPTS = 100;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
        private readonly int $statusDelay = 3,
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

    // The member the posts go out as, "urn:li:person:<id>"
    public function author(): string
    {
        return 'urn:li:person:' . $this->memberId();
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::AUTHORIZE . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->config('social-linkedin-client-id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'scope' => self::SCOPES,
        ]);
    }

    // Trades the code the callback received for the token, then asks who the member is - what the posts are signed with
    /** @return array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, name: string} */
    public function connect(string $code, string $redirectUri): array
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
        $member = $this->request('GET', '/v2/userinfo', null, $accessToken);
        if (!isset($member['sub'])) {
            throw new \RuntimeException('LinkedIn did not say which member is connected.');
        }

        return [
            'accessToken' => $accessToken,
            'memberId' => (string) $member['sub'],
            'expiresAt' => new \DateTimeImmutable('+' . (int) ($token['expires_in'] ?? 0) . ' seconds'),
            'name' => (string) ($member['name'] ?? $member['sub']),
        ];
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

    // Creates a post, answering its urn, which LinkedIn hands back in a header rather than in the body
    /** @param array<string, mixed> $post */
    public function createPost(array $post): string
    {
        $response = $this->send('POST', '/rest/posts', $post, (string) $this->accessToken());
        $urn = $response->getHeaders(false)['x-restli-id'][0] ?? null;
        if (null === $urn) {
            throw new \RuntimeException('LinkedIn did not answer the id of the post.');
        }

        return $urn;
    }

    // One API call, JSON both ways - the versioned headers sent on /rest only, /v2/userinfo being the OpenID endpoint
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

        // LinkedIn explains a refusal in the body ("Not enough permissions", an expired token), which is what the screen should show rather than a bare status code
        if ($response->getStatusCode() >= 300) {
            $data = json_decode($response->getContent(false), true);
            throw new \RuntimeException(sprintf('LinkedIn refused %s: %s', $path, \is_array($data) ? ($data['message'] ?? $response->getStatusCode()) : $response->getStatusCode()));
        }

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

    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }
}
