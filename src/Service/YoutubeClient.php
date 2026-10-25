<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Everything that talks to YouTube: the connection of the channel the videos go out on, then the upload YoutubePublisher makes - one stubable seam, same shape as LinkedInClient. The OAuth is GoogleOAuthClient's, the one the Business Profile already uses (same Google Cloud project, YouTube Data API v3 enabled in it), but the refresh token is its own: a Google token only covers the scopes granted in its consent, and the channel may belong to another Google account than the listing
class YoutubeClient
{
    public const string API = 'https://www.googleapis.com/youtube/v3';

    public const string UPLOAD = 'https://www.googleapis.com/upload/youtube/v3/videos';

    // Uploading, and reading which channel consented - what the tile links to
    public const string SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    // Where the channel's refresh token is stored
    public const string REFRESH_TOKEN = 'social-youtube-oauth-refresh-token';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly GoogleOAuthClient $googleOAuthClient,
    ) {
    }

    // Whether the site holds the Google OAuth client's credentials - the refresh token being what the connection goes and fetches
    public function isConfigured(): bool
    {
        return $this->googleOAuthClient->isConfigured();
    }

    // Whether a channel consented, the stored refresh token being the whole of it
    public function isConnected(): bool
    {
        return $this->googleOAuthClient->isConnected(self::REFRESH_TOKEN);
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return $this->googleOAuthClient->getAuthorizationUrl($redirectUri, $state, self::SCOPES);
    }

    // Trades the code the callback received for the refresh token, then asks which channel consented - Google's chooser lists a person's channels and brand accounts alike, so the channel is what proves the right one was picked
    /** @return array{refreshToken: string, channelId: string, channelTitle: string} */
    public function connect(string $code, string $redirectUri): array
    {
        $token = $this->googleOAuthClient->exchangeCode($code, $redirectUri);

        $channel = $this->request('GET', self::API . '/channels?part=snippet&mine=true', $token['accessToken'])['items'][0] ?? null;
        if (!\is_array($channel) || !isset($channel['id'])) {
            throw new \RuntimeException('The Google account that consented holds no YouTube channel.');
        }

        return [
            'refreshToken' => $token['refreshToken'],
            'channelId' => (string) $channel['id'],
            'channelTitle' => (string) ($channel['snippet']['title'] ?? $channel['id']),
        ];
    }

    // A short-lived access token for the channel, throwing when Google refuses the stored refresh token - what the health check asks too
    public function getAccessToken(): string
    {
        return $this->googleOAuthClient->getAccessToken(self::REFRESH_TOKEN);
    }

    // Uploads a video in one resumable session - announced with its metadata, then its bytes streamed from disk rather than read into memory - answering what YouTube made of it: its id, and the privacy it actually got, an unaudited API project having every upload locked private whatever was asked
    /**
     * @param array<string, mixed> $metadata the "snippet" and "status" parts of the video resource
     *
     * @return array{id: string, privacyStatus: string}
     */
    public function uploadVideo(string $path, string $mimeType, array $metadata): array
    {
        $size = @filesize($path);
        if (false === $size) {
            throw new \RuntimeException(sprintf('The video "%s" cannot be read.', $path));
        }

        $accessToken = $this->getAccessToken();
        $session = $this->httpClient->request('POST', self::UPLOAD . '?uploadType=resumable&part=snippet,status', [
            'auth_bearer' => $accessToken,
            'headers' => ['X-Upload-Content-Type' => $mimeType, 'X-Upload-Content-Length' => (string) $size],
            'json' => $metadata,
            'timeout' => 30,
        ]);
        $location = $this->checked($session, 'the upload')->getHeaders(false)['location'][0] ?? null;
        if (null === $location) {
            throw new \RuntimeException('YouTube gave no address to upload the video to.');
        }

        // Opened only for the time it is sent, so a refused session leaves nothing open
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            throw new \RuntimeException(sprintf('The video "%s" cannot be read.', $path));
        }

        try {
            $upload = $this->httpClient->request('PUT', $location, [
                'auth_bearer' => $accessToken,
                'headers' => ['Content-Type' => $mimeType, 'Content-Length' => (string) $size],
                'body' => $handle,
                'timeout' => 600,
            ]);
            $video = $this->checked($upload, 'the video')->toArray(false);
        } finally {
            fclose($handle);
        }

        if (!isset($video['id'])) {
            throw new \RuntimeException('YouTube did not answer the id of the video.');
        }

        return ['id' => (string) $video['id'], 'privacyStatus' => (string) ($video['status']['privacyStatus'] ?? '')];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $url, string $accessToken): array
    {
        $response = $this->httpClient->request($method, $url, ['auth_bearer' => $accessToken, 'timeout' => 30]);

        return $this->checked($response, $url)->toArray(false);
    }

    // YouTube explains a refusal in the body ("quotaExceeded", "uploadLimitExceeded"), which is what the screen should show rather than a bare status code
    private function checked(ResponseInterface $response, string $what): ResponseInterface
    {
        if ($response->getStatusCode() >= 300) {
            $data = json_decode($response->getContent(false), true);
            throw new \RuntimeException(sprintf('YouTube refused %s: %s', $what, \is_array($data) ? ($data['error']['message'] ?? $response->getStatusCode()) : $response->getStatusCode()));
        }

        return $response;
    }
}
