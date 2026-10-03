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
use c975L\SocialBundle\Service\LinkedInClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the LinkedIn connection, the same shape as the Meta one (see MetaOAuthController): the member consents, and the site keeps the token the posts on the member's profile are made with - for its 60 days, LinkedIn handing a self-serve app no refresh token
class LinkedInOAuthController extends AbstractController
{
    private const string SESSION_STATE = 'social_linkedin_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly LinkedInClient $linkedInClient,
    ) {
    }

    #[Route('/social/linkedin/connect', name: 'social_linkedin_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->linkedInClient->isConfigured()) {
            $this->addFlash('danger', t('flash.linkedin_not_configured', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Held in the session and checked on the way back: without it the callback would accept a code obtained by anyone who can make the editor's browser follow a link
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);

        return $this->redirect($this->linkedInClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/linkedin/callback', name: 'social_linkedin_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $expected = $request->getSession()->remove(self::SESSION_STATE);
        $code = $request->query->get('code');
        if (!\is_string($expected) || $expected !== $request->query->get('state') || !\is_string($code) || '' === $code) {
            $this->addFlash('danger', t('flash.linkedin_refused', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $connection = $this->linkedInClient->connect($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $this->configValueWriter->write([
            'social-linkedin-access-token' => $connection['accessToken'],
            'social-linkedin-member-id' => $connection['memberId'],
            'social-linkedin-token-expires-at' => $connection['expiresAt']->format(\DateTimeInterface::ATOM),
        ]);

        $this->addFlash('success', t('flash.linkedin_connected', ['%name%' => $connection['name'], '%date%' => $connection['expiresAt']->format('d/m/Y')], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect url declared in the LinkedIn app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_linkedin_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
