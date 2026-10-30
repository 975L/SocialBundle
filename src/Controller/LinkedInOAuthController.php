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
use c975L\SocialBundle\Controller\Management\SocialLinkedInPageController;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\LinkedInClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

use function Symfony\Component\Translation\t;

// The two routes of the LinkedIn connection, the same shape as the Meta one (see MetaOAuthController): the member consents, and the site keeps the token the posts on the member's profile, or on a Page they administer, are made with - for its 60 days, LinkedIn handing no refresh token. One callback for both, so the redirect url declared in the app stays the same
class LinkedInOAuthController extends AbstractController
{
    use OAuthStateTrait;

    private const string SESSION_STATE = 'social_linkedin_oauth_state';

    // Whether the connection under way is a Page's, kept from the click to the callback
    private const string SESSION_PAGE = 'social_linkedin_oauth_page';

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

        $state = $this->newState($request);
        $page = $request->query->getBoolean('page');
        $request->getSession()->set(self::SESSION_PAGE, $page);

        return $this->redirect($this->linkedInClient->getAuthorizationUrl($this->redirectUri(), $state, $page));
    }

    #[Route('/social/linkedin/callback', name: 'social_linkedin_oauth_callback', methods: ['GET'])]
    public function callback(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $code = $this->callbackCode($request);
        $page = true === $request->getSession()->remove(self::SESSION_PAGE);

        // A connection held by an earlier one never picked from, so it cannot outlive this one
        $request->getSession()->remove(SocialLinkedInPageController::SESSION_CONNECTION);

        // A consent asking scopes the app was not granted, the Page button on a profile app or the other way round, said as such rather than as a bare refusal
        if ('unauthorized_scope_error' === $request->query->get('error')) {
            $this->addFlash('danger', t('flash.linkedin_wrong_app', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if (null === $code) {
            $this->addFlash('danger', t('flash.linkedin_refused', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        // Everything fetched before anything is written: a failure on a reconnection leaves the working connection as it was
        try {
            $connection = $this->linkedInClient->connect($code, $this->redirectUri(), $page);
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        return $page ? $this->page($request, $connection) : $this->profile($connection);
    }

    // The member's own profile: a Page connected before is emptied, or the posts would still go out on it
    /** @param array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, name: string, organizations: list<array{id: string, name: string}>} $connection */
    private function profile(array $connection): RedirectResponse
    {
        $this->configValueWriter->write(LinkedInClient::connectionValues($connection));

        // A site not yet through load-all has no Page to empty, and keeps its connection
        try {
            $this->configValueWriter->write(['social-linkedin-organization-id' => null, 'social-linkedin-organization-name' => null]);
        } catch (\RuntimeException) {
        }

        $this->addFlash('success', t('flash.linkedin_connected', ['%name%' => $connection['name'], '%date%' => $connection['expiresAt']->format('d/m/Y')], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }

    // A Page the member administers: none refused, a single one stored at once, several held in the session - the end as a string - for "Choisir la Page LinkedIn" to pick from
    /** @param array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, name: string, organizations: list<array{id: string, name: string}>} $connection */
    private function page(Request $request, array $connection): RedirectResponse
    {
        if ([] === $connection['organizations']) {
            $this->addFlash('danger', t('flash.linkedin_no_page', ['%name%' => $connection['name']], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if (1 === \count($connection['organizations'])) {
            $this->configValueWriter->write(LinkedInClient::connectionValues($connection, $connection['organizations'][0]));
            $this->addFlash('success', t('flash.linkedin_page_connected', ['%name%' => $connection['organizations'][0]['name'], '%date%' => $connection['expiresAt']->format('d/m/Y')], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        $request->getSession()->set(SocialLinkedInPageController::SESSION_CONNECTION, [
            'expires' => time() + SocialLinkedInPageController::SESSION_TTL,
            'connection' => ['expiresAt' => $connection['expiresAt']->format(\DateTimeInterface::ATOM)] + $connection,
        ]);

        return $this->redirectToRoute(SocialLinkedInPageController::ROUTE);
    }

    // Absolute, and built from the route rather than configured: it has to match the redirect url declared in the LinkedIn app character for character
    private function redirectUri(): string
    {
        return $this->generateUrl('social_linkedin_oauth_callback', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
