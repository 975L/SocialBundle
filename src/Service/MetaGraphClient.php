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
    public const string GRAPH = 'https://graph.facebook.com/v26.0';

    public const string DIALOG = 'https://www.facebook.com/v26.0/dialog/oauth';

    // Posting on the Page, and on the Instagram account linked to it
    public const string SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish';

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

    // Trades the code the callback received for what the posts need: the site's Page, its token - one that does not expire, being derived from a long-lived user token - and the Instagram account linked to it, if any
    /** @return array{pageId: string, pageToken: string, instagramId: ?string} */
    public function connect(string $code, string $redirectUri): array
    {
        $credentials = ['client_id' => $this->config('social-meta-app-id'), 'client_secret' => $this->config('social-meta-app-secret')];
        $short = $this->request('GET', '/oauth/access_token', [...$credentials, 'redirect_uri' => $redirectUri, 'code' => $code]);
        $long = $this->request('GET', '/oauth/access_token', [...$credentials, 'grant_type' => 'fb_exchange_token', 'fb_exchange_token' => (string) $short['access_token']]);

        $pages = $this->request('GET', '/me/accounts', ['access_token' => (string) $long['access_token'], 'fields' => 'id,access_token'])['data'] ?? [];
        $page = $this->sitePage(\is_array($pages) ? $pages : []);

        $linked = $this->request('GET', '/' . $page['id'], ['access_token' => (string) $page['access_token'], 'fields' => 'instagram_business_account']);

        return [
            'pageId' => (string) $page['id'],
            'pageToken' => (string) $page['access_token'],
            'instagramId' => isset($linked['instagram_business_account']['id']) ? (string) $linked['instagram_business_account']['id'] : null,
        ];
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

    // The Page already set in "social-meta-page-id", so an owner managing the Pages of several sites connects each to its own - the first one managed while none is set
    /**
     * @param array<mixed> $pages
     *
     * @return array<string, mixed>
     */
    private function sitePage(array $pages): array
    {
        $pageId = $this->config('social-meta-page-id');
        foreach ($pages as $page) {
            if (\is_array($page) && ('' === $pageId || (string) $page['id'] === $pageId)) {
                return $page;
            }
        }

        throw new \RuntimeException('' === $pageId ? 'This Facebook account manages no Page: the posts need one to go out under.' : sprintf('This Facebook account does not manage the Page "%s" set in "social-meta-page-id".', $pageId));
    }

    private function config(string $slug): string
    {
        return trim((string) $this->configService->get($slug));
    }
}
