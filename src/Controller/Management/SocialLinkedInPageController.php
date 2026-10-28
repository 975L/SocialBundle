<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\ConfigValueWriter;
use c975L\SocialBundle\Service\LinkedInClient;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

// The end of a LinkedIn Page connection when the member administers several Pages (see LinkedInOAuthController::page()): the whole connection held in the session, nothing written until one Page is picked on this screen - the same shape as SocialMetaPageController
class SocialLinkedInPageController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_linkedin_page';

    // Holds ['expires' => timestamp, 'connection' => the connection, its token and its end as a string included]
    public const string SESSION_CONNECTION = 'social_linkedin_oauth_connection';

    // Long enough to pick, short enough not to leave the token lying in the session
    public const int SESSION_TTL = SocialMetaPageController::SESSION_TTL;

    private const string CSRF_TOKEN = 'social_linkedin_page';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
    ) {
    }

    // The Pages the member administers, each opened on LinkedIn, the one picked being the site's
    #[AdminRoute(path: '/social-linkedin-page', name: 'social_linkedin_page')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $connection = $this->heldConnection($request);
        if (null === $connection) {
            $request->getSession()->remove(self::SESSION_CONNECTION);

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
                foreach ($connection['organizations'] as $organization) {
                    if ($organization['id'] === $request->request->getString('organization')) {
                        $request->getSession()->remove(self::SESSION_CONNECTION);

                        return $this->store($connection, $organization);
                    }
                }
            }

            // A token gone stale or a Page not in the list: said, rather than the screen coming back as if the click did nothing
            $this->addFlash('danger', t('flash.linkedin_page_invalid', [], 'social'));
        }

        return $this->render('@c975LSocial/management/social_linkedin_page.html.twig', [
            'organizations' => $connection['organizations'],
            'current' => trim((string) $this->configService->get('social-linkedin-organization-id')),
            'token' => self::CSRF_TOKEN,
        ]);
    }

    // The connection the callback held, unless held too long or not of the shape this code writes (a session from another version of it) - its Pages that are not left out
    /** @return array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable, name: string, organizations: list<array{id: string, name: string}>}|null */
    private function heldConnection(Request $request): ?array
    {
        $held = $request->getSession()->get(self::SESSION_CONNECTION);
        $connection = \is_array($held) && ($held['expires'] ?? 0) >= time() ? ($held['connection'] ?? null) : null;
        if (!\is_array($connection) || !\is_string($connection['accessToken'] ?? null) || !\is_string($connection['memberId'] ?? null) || !\is_string($connection['expiresAt'] ?? null) || !\is_array($connection['organizations'] ?? null)) {
            return null;
        }

        $organizations = array_values(array_filter($connection['organizations'], static fn (mixed $organization): bool => \is_array($organization) && \is_string($organization['id'] ?? null) && \is_string($organization['name'] ?? null)));
        if ([] === $organizations) {
            return null;
        }

        try {
            $expiresAt = new \DateTimeImmutable($connection['expiresAt']);
        } catch (\Exception) {
            return null;
        }

        return ['accessToken' => $connection['accessToken'], 'memberId' => $connection['memberId'], 'expiresAt' => $expiresAt, 'name' => (string) ($connection['name'] ?? ''), 'organizations' => $organizations];
    }

    // The token, the member and the Page picked, written at once - its name kept for "Connexions" to show and link it
    /**
     * @param array{accessToken: string, memberId: string, expiresAt: \DateTimeImmutable} $connection
     * @param array{id: string, name: string}                                             $organization
     */
    private function store(array $connection, array $organization): RedirectResponse
    {
        $this->configValueWriter->write(LinkedInClient::connectionValues($connection, $organization));

        $this->addFlash('success', t('flash.linkedin_page_connected', ['%name%' => $organization['name'], '%date%' => $connection['expiresAt']->format('d/m/Y')], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }
}
