<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use c975L\SocialBundle\Service\LinkedInClient;
use c975L\SocialBundle\Service\MetaGraphClient;
use c975L\SocialBundle\Service\YoutubeClient;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Translation\TranslatableMessage;

use function Symfony\Component\Translation\t;

// One screen for every network the site connects to, connected or not: what each connection is for, where it stands, the accounts it reaches - each opened on its network - and the button that makes or remakes it - the connected accounts being what turns the publication on (see SocialPublisher::isEnabled())
class SocialConnectionsController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see GalleryMediaUploadController::UPLOAD_ROUTE)
    public const string ROUTE = 'management_social_connections';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly BlueskyOAuthClient $blueskyOAuthClient,
        private readonly MetaGraphClient $metaGraphClient,
        private readonly GoogleOAuthClient $googleOAuthClient,
        private readonly LinkedInClient $linkedInClient,
        private readonly YoutubeClient $youtubeClient,
    ) {
    }

    #[AdminRoute(path: '/social-connections', name: 'social_connections')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        return $this->render('@c975LSocial/management/social_connections.html.twig', [
            'networks' => [
                $this->bluesky($request->getHost()),
                $this->meta(),
                $this->linkedIn(),
                $this->youtube(),
                $this->google(),
            ],
        ]);
    }

    // Needs no app of its own, but has to be started from "site-url", the address Bluesky knows the site by
    /** @return array<string, mixed> */
    private function bluesky(string $host): array
    {
        $handle = $this->value('social-bluesky-handle');
        $siteUrl = null;
        try {
            $siteUrl = $this->blueskyOAuthClient->isSiteHost($host) ? null : $this->blueskyOAuthClient->siteUrl();
        } catch (\RuntimeException) {
            // "site-url" not set: the connection says so itself once started
        }

        return [
            'name' => 'bluesky',
            'icon' => 'fab fa-bluesky',
            'route' => 'social_bluesky_oauth_connect',
            'configured' => true,
            'connected' => $this->blueskyOAuthClient->isConnected(),
            'accounts' => $this->accounts(['fab fa-bluesky', $handle, null === $handle ? null : 'https://bsky.app/profile/' . $handle]),
            'site_url' => $siteUrl,
        ];
    }

    // Facebook and Instagram, both reached through the Page token the Meta app hands over
    /** @return array<string, mixed> */
    private function meta(): array
    {
        $page = $this->value('social-meta-page-id');
        $instagram = $this->value('social-meta-instagram-id');
        $username = $this->value('social-meta-instagram-username');

        // The Page's name and the Instagram username are stored by the connection since 2.14: one made before shows the Page's id, and the Instagram account unnamed and unlinked
        $pageName = null === $page ? null : ($this->value('social-meta-page-name') ?? $page);
        $instagramName = null === $instagram ? null : (null === $username ? t('label.social_meta_page_instagram_linked', [], 'social') : '@' . $username);
        $instagramUrl = null === $username ? null : 'https://www.instagram.com/' . $username . '/';

        return [
            'name' => 'meta',
            'icon' => 'fab fa-meta',
            'route' => 'social_meta_oauth_connect',
            'configured' => $this->metaGraphClient->isConfigured(),
            'connected' => null !== $this->metaGraphClient->pageToken(),
            'accounts' => $this->accounts(
                ['fab fa-facebook', $pageName, null === $page ? null : 'https://www.facebook.com/' . $page],
                ['fab fa-instagram', $instagramName, $instagramUrl],
            ),
            'instagram' => null !== $instagram,
        ];
    }

    // The member's own profile, its token lasting 60 days - the date it ends shown, the reconnection being the owner's to make
    /** @return array<string, mixed> */
    private function linkedIn(): array
    {
        return [
            'name' => 'linkedin',
            'icon' => 'fab fa-linkedin',
            'route' => 'social_linkedin_oauth_connect',
            'configured' => $this->linkedInClient->isConfigured(),
            'connected' => $this->linkedInClient->isConnected(),
            'accounts' => [],
            'expires_at' => $this->linkedInClient->expiresAt(),
        ];
    }

    // The channel the videos go out on, linked so the owner sees at once which one consented - Google's chooser listing a person's channels and brand accounts alike
    /** @return array<string, mixed> */
    private function youtube(): array
    {
        $channel = $this->value('social-youtube-channel-id');

        return [
            'name' => 'youtube',
            'icon' => 'fab fa-youtube',
            'route' => 'social_youtube_oauth_connect',
            'configured' => $this->youtubeClient->isConfigured(),
            'connected' => $this->youtubeClient->isConnected(),
            'accounts' => $this->accounts(['fab fa-youtube', $this->value('social-youtube-channel-title') ?? $channel, null === $channel ? null : 'https://www.youtube.com/channel/' . $channel]),
        ];
    }

    // The Business Profile the reviews are read from and answered on
    /** @return array<string, mixed> */
    private function google(): array
    {
        return [
            'name' => 'google',
            'icon' => 'fab fa-google',
            'route' => 'social_google_oauth_connect',
            'configured' => $this->googleOAuthClient->isConfigured(),
            'connected' => $this->googleOAuthClient->isConnected(),
            'accounts' => $this->accounts(['fab fa-google', $this->value('social-google-business-location-id'), null]),
        ];
    }

    // The accounts a connection reaches, each given as [icon, name, link] - one with no name left out, one known by no name yet given a label to translate, one with no link shown as its name alone
    /**
     * @param array{0: string, 1: string|TranslatableMessage|null, 2: ?string} ...$accounts
     *
     * @return list<array{icon: string, name: string|TranslatableMessage, url: ?string}>
     */
    private function accounts(array ...$accounts): array
    {
        $kept = [];
        foreach ($accounts as [$icon, $name, $url]) {
            if (null !== $name) {
                $kept[] = ['icon' => $icon, 'name' => $name, 'url' => $url];
            }
        }

        return $kept;
    }

    private function value(string $slug): ?string
    {
        $value = trim((string) $this->configService->get($slug));

        return '' === $value ? null : $value;
    }
}
