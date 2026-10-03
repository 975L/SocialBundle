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
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

// One screen for every network the site connects to, connected or not: what each connection is for, where it stands, and the button that makes or remakes it - the connected accounts being what turns the publication on (see SocialPublisher::isEnabled())
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
                $this->google(),
            ],
        ]);
    }

    // Needs no app of its own, but has to be started from "site-url", the address Bluesky knows the site by
    /** @return array<string, mixed> */
    private function bluesky(string $host): array
    {
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
            'account' => $this->value('social-bluesky-handle'),
            'site_url' => $siteUrl,
        ];
    }

    // Facebook and Instagram, both reached through the Page token the Meta app hands over
    /** @return array<string, mixed> */
    private function meta(): array
    {
        $instagram = $this->value('social-meta-instagram-id');

        return [
            'name' => 'meta',
            'icon' => 'fab fa-meta',
            'route' => 'social_meta_oauth_connect',
            'configured' => $this->metaGraphClient->isConfigured(),
            'connected' => null !== $this->metaGraphClient->pageToken(),
            'account' => $this->value('social-meta-page-id'),
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
            'account' => null,
            'expires_at' => $this->linkedInClient->expiresAt(),
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
            'account' => $this->value('social-google-business-location-id'),
        ];
    }

    private function value(string $slug): ?string
    {
        $value = trim((string) $this->configService->get($slug));

        return '' === $value ? null : $value;
    }
}
