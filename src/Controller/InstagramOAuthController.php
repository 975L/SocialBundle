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
use c975L\SocialBundle\Service\InstagramLoginClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the Instagram connection with no Facebook Page, the same shape as the Pinterest one (see PinterestOAuthController): the account consents, the site keeps its token and its name
class InstagramOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_instagram_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly InstagramLoginClient $instagramLoginClient,
    ) {
    }

    #[Route('/social/instagram/connect', name: 'social_instagram_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->instagramLoginClient->isConfigured()) {
            $this->addFlash('danger', t('flash.instagram_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $state = $this->newState($request);

        return $this->redirect($this->instagramLoginClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/instagram/callback', name: 'social_instagram_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $values = $this->callbackConnection($request, 'flash.instagram_refused', fn (string $code) => $this->instagramLoginClient->connect($code, $this->redirectUri()));
        if ($values instanceof RedirectResponse) {
            return $values;
        }

        $this->configValueWriter->write($values);
        $this->addFlash('success', t('flash.instagram_connected', ['%name%' => $values['social-instagram-username']], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect url declared in the app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_instagram_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
