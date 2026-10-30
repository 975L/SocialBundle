<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\YoutubeClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the YouTube connection, the same shape as the LinkedIn one (see LinkedInOAuthController): the channel's owner consents with the Google OAuth client the site already holds, and the site keeps the refresh token and which channel it reaches
class YoutubeOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_youtube_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly YoutubeClient $youtubeClient,
    ) {
    }

    #[Route('/social/youtube/connect', name: 'social_youtube_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->youtubeClient->isConfigured()) {
            $this->addFlash('danger', t('flash.youtube_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $state = $this->newState($request);

        return $this->redirect($this->youtubeClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/youtube/callback', name: 'social_youtube_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $code = $this->callbackCode($request);
        if (null === $code) {
            $this->addFlash('danger', t('flash.youtube_refused', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $connection = $this->youtubeClient->connect($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $this->configValueWriter->write([
            'social-youtube-oauth-refresh-token' => $connection['refreshToken'],
            'social-youtube-channel-id' => $connection['channelId'],
            'social-youtube-channel-title' => $connection['channelTitle'],
        ]);

        $this->addFlash('success', t('flash.youtube_connected', ['%name%' => $connection['channelTitle']], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match a redirect URI declared on the Google OAuth client character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_youtube_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
