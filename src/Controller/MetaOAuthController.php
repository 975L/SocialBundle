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
use c975L\SocialBundle\Service\MetaGraphClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the Meta connection, the same shape as the Google one (see GoogleOAuthController): the owner consents once, and the site keeps the Page token the posts on Facebook and Instagram are made with
class MetaOAuthController extends AbstractController
{
    private const string SESSION_STATE = 'social_meta_oauth_state';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly MetaGraphClient $metaGraphClient,
    ) {
    }

    #[Route('/social/meta/connect', name: 'social_meta_oauth_connect', methods: ['GET'])]
    public function connect(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->metaGraphClient->isConfigured()) {
            $this->addFlash('danger', t('flash.meta_not_configured', [], 'social'));

            return $this->redirectToRoute('management');
        }

        // Held in the session and checked on the way back: without it the callback would accept a code obtained by anyone who can make the editor's browser follow a link
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);

        return $this->redirect($this->metaGraphClient->getAuthorizationUrl($this->redirectUri(), $state));
    }

    #[Route('/social/meta/callback', name: 'social_meta_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $expected = $request->getSession()->remove(self::SESSION_STATE);
        $code = $request->query->get('code');
        if (!\is_string($expected) || $expected !== $request->query->get('state') || !\is_string($code) || '' === $code) {
            $this->addFlash('danger', t('flash.meta_refused', [], 'social'));

            return $this->redirectToRoute('management');
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $connection = $this->metaGraphClient->connect($code, $this->redirectUri());
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute('management');
        }

        $this->configValueWriter->write([
            'social-meta-page-id' => $connection['pageId'],
            'social-meta-page-token' => $connection['pageToken'],
            'social-meta-instagram-id' => $connection['instagramId'],
        ]);

        $this->addFlash('success', t(null === $connection['instagramId'] ? 'flash.meta_connected_without_instagram' : 'flash.meta_connected', [], 'social'));

        return $this->redirectToRoute('management');
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect uri declared in the Meta app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_meta_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
