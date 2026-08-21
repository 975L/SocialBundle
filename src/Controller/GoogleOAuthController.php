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
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\GoogleBusinessLocationResolver;
use c975L\SocialBundle\Service\GoogleOAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// The two routes of the Google connection - the bundle's first, everything else it does being blocks, Twig functions and dashboard entries
class GoogleOAuthController extends AbstractController
{
    private const string SESSION_STATE = 'social_google_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly GoogleOAuthClient $googleOAuthClient,
        private readonly GoogleBusinessLocationResolver $googleBusinessLocationResolver,
    ) {
    }

    #[Route('/social/google/connect', name: 'social_google_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->googleOAuthClient->isConfigured()) {
            $this->addFlash('danger', 'review.google_not_configured');

            return $this->redirectToRoute('management');
        }

        // Held in the session and checked on the way back: without it the callback would accept a code obtained by anyone who can make the editor's browser follow a link
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);

        return $this->redirect($this->googleOAuthClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/google/callback', name: 'social_google_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $session = $request->getSession();
        $expected = $session->remove(self::SESSION_STATE);
        $code = $request->query->get('code');

        if (!is_string($expected) || $expected !== $request->query->get('state')) {
            $this->addFlash('danger', 'review.google_state_mismatch');

            return $this->redirectToRoute('management');
        }

        if (!is_string($code) || '' === $code) {
            $this->addFlash('danger', 'review.google_authorization_refused');

            return $this->redirectToRoute('management');
        }

        // The exchange on its own, nothing written yet: a network hiccup on a reconnection must leave the working connection exactly as it was
        try {
            $refreshToken = $this->googleOAuthClient->exchangeCode($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('management');
        }

        try {
            // Written before the listing is resolved: resolving it needs an access token, which is minted from this very refresh token
            $this->configValueWriter->write(['social-google-oauth-refresh-token' => $refreshToken]);

            $listing = $this->googleBusinessLocationResolver->resolveFirst();
            $this->configValueWriter->write([
                'social-google-business-account-id' => $listing['accountId'],
                'social-google-business-location-id' => $listing['locationId'],
            ]);
        } catch (\Throwable $exception) {
            // Back to "not connected" rather than a fresh token left paired with the previous account's listing, which would answer 403 on every later synchronization without saying why
            $this->configValueWriter->write([
                'social-google-oauth-refresh-token' => null,
                'social-google-business-account-id' => null,
                'social-google-business-location-id' => null,
            ]);

            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('management');
        }

        $this->addFlash('success', 'review.google_connected');

        return $this->redirectToRoute('management');
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect uri declared in the Google Cloud console character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_google_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
