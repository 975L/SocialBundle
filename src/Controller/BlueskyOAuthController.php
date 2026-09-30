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
use c975L\SocialBundle\Service\BlueskyOAuthClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

// The Bluesky connection: the two public documents the AT Protocol reads to know the site as a client - no app registered anywhere - then the same connect/callback pair as Meta's (see MetaOAuthController)
class BlueskyOAuthController extends AbstractController
{
    private const string SESSION_PENDING = 'social_bluesky_oauth_pending';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly BlueskyOAuthClient $oauthClient,
    ) {
    }

    // The client id itself: Bluesky's servers fetch it, anonymously, to learn the site's redirect uri and key
    #[Route('/social/bluesky/client-metadata.json', name: 'social_bluesky_oauth_client_metadata', methods: ['GET'])]
    public function clientMetadata(): JsonResponse
    {
        return new JsonResponse($this->oauthClient->clientMetadata(), headers: ['Cache-Control' => 'public, max-age=600']);
    }

    #[Route('/social/bluesky/jwks.json', name: 'social_bluesky_oauth_jwks', methods: ['GET'])]
    public function jwks(): JsonResponse
    {
        return new JsonResponse($this->oauthClient->jwks(), headers: ['Cache-Control' => 'public, max-age=600']);
    }

    // The handle already set is the account asked for, none letting the owner pick it on Bluesky's page
    #[Route('/social/bluesky/connect', name: 'social_bluesky_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $handle = trim((string) $this->configService->get('social-bluesky-handle'));

        try {
            $authorization = $this->oauthClient->startAuthorization('' === $handle ? null : $handle, bin2hex(random_bytes(16)));
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('management');
        }

        // Held in the session and checked on the way back, with the PKCE verifier and the DPoP key the tokens will be bound to
        $request->getSession()->set(self::SESSION_PENDING, $authorization['pending']);

        return $this->redirect($authorization['url']);
    }

    #[Route('/social/bluesky/callback', name: 'social_bluesky_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $pending = $request->getSession()->remove(self::SESSION_PENDING);
        $code = $request->query->get('code');
        if (!\is_array($pending) || ($pending['state'] ?? null) !== $request->query->get('state') || !\is_string($code) || '' === $code) {
            $this->addFlash('danger', t('flash.bluesky_refused', [], 'social'));

            return $this->redirectToRoute('management');
        }

        try {
            $this->oauthClient->finishAuthorization($pending, $code, $request->query->getString('iss'));
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('management');
        }

        $this->addFlash('success', t('flash.bluesky_connected', [], 'social'));

        return $this->redirectToRoute('management');
    }
}
