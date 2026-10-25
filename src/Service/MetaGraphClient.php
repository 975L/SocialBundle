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

// Everything that talks to Meta's Graph API: the connection of the site's Facebook Page (and the Instagram account linked to it), then the calls the two publishers make with the Page token it stores - one stubable seam, same shape as GoogleOAuthClient. The app is the site owner's own, created on developers.facebook.com and used by its admin alone: in development mode it needs neither App Review nor Business Verification
class MetaGraphClient
{
    // The version every call is pinned to, v26.0 being current in September 2026 - bumped here, once, when Meta retires it (see the changelog on developers.facebook.com)
    public const string VERSION = 'v26.0';

    public const string GRAPH = 'https://graph.facebook.com/' . self::VERSION;

    public const string DIALOG = 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth';

    // Posting on the Page, and on the Instagram account linked to it - business_management lists the Pages held through a business portfolio
    public const string SCOPES = 'business_management,pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Whether the site holds its Meta app's credentials, the Page token aside - which is precisely what the connection goes and fetches
    public function isConfigured(): bool
    {
        return '' !== $this->config('social-meta-app-id') && '' !== $this->config('social-meta-app-secret');
    }

    // The Page token every post is made with, null before the connection was made
    public function pageToken(): ?string
    {
        return '' === $this->config('social-meta-page-token') ? null : $this->config('social-meta-page-token');
    }

    public function getAuthorizationUrl(string $redirectUri, string $state): string
    {
        return self::DIALOG . '?' . http_build_query([
            'client_id' => $this->config('social-meta-app-id'),
            'redirect_uri' => $redirectUri,
            'state' => $state,
            'response_type' => 'code',
            'scope' => self::SCOPES,
        ]);
    }

    // Trades the code the callback received for the Pages the account manages, each with its token - one that does not expire, being derived from a long-lived user token - and the Instagram account linked to it, if any
    /** @return non-empty-list<array{pageId: string, name: string, pageToken: string, instagramId: ?string, instagram: ?string}> */
    public function connect(string $code, string $redirectUri): array
    {
        $credentials = ['client_id' => $this->config('social-meta-app-id'), 'client_secret' => $this->config('social-meta-app-secret')];
        $short = $this->request('GET', '/oauth/access_token', [...$credentials, 'redirect_uri' => $redirectUri, 'code' => $code]);
        $long = $this->request('GET', '/oauth/access_token', [...$credentials, 'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => (string) $short['access_token']]);

        $pages = $this->request('GET', '/me/accounts', ['access_token' => (string) $long['access_token'], 'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => '100'])['data'] ?? [];

        $connections = array_values(array_filter(array_map($this->page(...), \is_array($pages) ? $pages : [])));

        if ([] === $connections) {
            throw new \RuntimeException('This Facebook account manages no Page: the posts need one to go out under.');
        }

        return $connections;
    }

    // One Graph call, parameters sent in the query for a GET and in the body otherwise
    /**
     * @param array<string, string> $parameters
     *
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $parameters): array
    {
        $response = $this->httpClient->request($method, self::GRAPH . $path, [
            'GET' === $method ? 'query' : 'body' => $parameters,
            'timeout' => 30,
        ]);

        // Meta explains a refusal in the body ("Invalid OAuth access token", "(#10) ... permission"), which is what the screen should show rather than a bare status code
        $data = $response->toArray(false);
        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException(sprintf('Meta refused %s: %s', $path, $data['error']['message'] ?? $response->getStatusCode()));
        }

        return $data;
    }

    // Hands Meta a video it downloads itself, at the upload address a reel's first phase gave out - outside the Graph host, and authorized by the Page token in a header rather than a parameter
    public function uploadHosted(string $uploadUrl, string $fileUrl, string $token): void
    {
        $response = $this->httpClient->request('POST', $uploadUrl, [
            'headers' => ['Authorization' => 'OAuth ' . $token, 'file_url' => $fileUrl],
            'timeout' => 60,
        ]);

        $data = $response->toArray(false);
        if (200 !== $response->getStatusCode() || true !== ($data['success'] ?? null)) {
            throw new \RuntimeException(sprintf('Meta refused the reel upload: %s', $data['debug_info']['message'] ?? $data['error']['message'] ?? $response->getStatusCode()));
        }
    }

    // One Page as Meta lists it, read into what the site keeps of it - null for an entry missing its id or its token
    /** @return array{pageId: string, name: string, pageToken: string, instagramId: ?string, instagram: ?string}|null */
    private function page(mixed $page): ?array
    {
        if (!\is_array($page) || !isset($page['id'], $page['access_token'])) {
            return null;
        }

        $instagram = \is_array($page['instagram_business_account'] ?? null) ? $page['instagram_business_account'] : [];

        return [
            'pageId' => (string) $page['id'],
            'name' => (string) ($page['name'] ?? $page['id']),
            'pageToken' => (string) $page['access_token'],
            'instagramId' => isset($instagram['id']) ? (string) $instagram['id'] : null,
            'instagram' => isset($instagram['username']) ? (string) $instagram['username'] : null,
        ];
    }

    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }
}
