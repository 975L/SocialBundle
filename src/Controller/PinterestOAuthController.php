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
use c975L\SocialBundle\Controller\Management\SocialPinterestBoardController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\PinterestClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the Pinterest connection, the same shape as the LinkedIn one (see LinkedInOAuthController): the business account consents, the site keeps its tokens, then the board the pins go on is picked - at once when there is a single one, on "Choisir le tableau Pinterest" otherwise
class PinterestOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_pinterest_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly PinterestClient $pinterestClient,
    ) {
    }

    #[Route('/social/pinterest/connect', name: 'social_pinterest_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->pinterestClient->isConfigured()) {
            $this->addFlash('danger', t('flash.pinterest_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $state = $this->newState($request);

        return $this->redirect($this->pinterestClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/pinterest/callback', name: 'social_pinterest_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $code = $this->callbackCode($request);
        if (null === $code) {
            $this->addFlash('danger', t('flash.pinterest_refused', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $values = $this->pinterestClient->connect($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Another account's board would not take this one's pins: the board is picked again, the same account's kept
        $sameAccount = $values['social-pinterest-username'] === trim((string) $this->configService->get('social-pinterest-username'));
        $this->configValueWriter->write($values + ($sameAccount ? [] : ['social-pinterest-board-id' => null, 'social-pinterest-board-name' => null]));
        $this->addFlash('success', t('flash.pinterest_connected', ['%name%' => $values['social-pinterest-username']], 'social'));

        return $this->redirectToRoute(SocialPinterestBoardController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect url declared in the Pinterest app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_pinterest_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
