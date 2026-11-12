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
use c975L\SocialBundle\Service\TikTokClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the TikTok connection, the same shape as the Pinterest one (see PinterestOAuthController): the account consents, the site keeps its tokens and its name, then "Connexions" shows it
class TikTokOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_tiktok_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly TikTokClient $tikTokClient,
    ) {
    }

    #[Route('/social/tiktok/connect', name: 'social_tiktok_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->tikTokClient->isConfigured()) {
            $this->addFlash('danger', t('flash.tiktok_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $state = $this->newState($request);

        return $this->redirect($this->tikTokClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/tiktok/callback', name: 'social_tiktok_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $values = $this->callbackConnection($request, 'flash.tiktok_refused', fn (string $code) => $this->tikTokClient->connect($code, $this->redirectUri()));
        if ($values instanceof RedirectResponse) {
            return $values;
        }

        $this->configValueWriter->write($values);
        $this->addFlash('success', t('flash.tiktok_connected', ['%name%' => htmlspecialchars($values['social-tiktok-display-name'])], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect url declared in the TikTok app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_tiktok_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
