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
use c975L\SocialBundle\Controller\Management\SocialMetaPageController;
use c975L\SocialBundle\Service\MetaGraphClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the Meta connection, the same shape as the Google one (see GoogleOAuthController): the owner consents once, and the site keeps the Page token the posts on Facebook and Instagram are made with - the Page itself stored by SocialMetaPageController, where an account managing several picks the site's own
class MetaOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_meta_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly MetaGraphClient $metaGraphClient,
    ) {
    }

    #[Route('/social/meta/connect', name: 'social_meta_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->metaGraphClient->isConfigured()) {
            $this->addFlash('danger', t('flash.meta_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $state = $this->newState($request);

        return $this->redirect($this->metaGraphClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/meta/callback', name: 'social_meta_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $code = $this->callbackCode($request);

        // A list left by an earlier connection never picked from, so it cannot outlive this one
        $request->getSession()->remove(SocialMetaPageController::SESSION_PAGES);

        if (null === $code) {
            $this->addFlash('danger', t('flash.meta_refused', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $pages = $this->metaGraphClient->connect($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Held in the session, tokens included, for the screen that stores the site's Page - straight away when the account manages only one
        $request->getSession()->set(SocialMetaPageController::SESSION_PAGES, ['expires' => time() + SocialMetaPageController::SESSION_TTL, 'pages' => $pages]);

        return $this->redirectToRoute(SocialMetaPageController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect uri declared in the Meta app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_meta_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
