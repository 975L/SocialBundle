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
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

// The end of the Meta connection (see MetaOAuthController::callback()): the Pages the account manages, held in the session, one of them becoming the site's - picked on this screen, or stored straight away when it is the only one
class SocialMetaPageController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_meta_page';

    // Holds ['expires' => timestamp, 'pages' => list of Pages], tokens included
    public const string SESSION_PAGES = 'social_meta_oauth_pages';

    // Long enough to pick, short enough not to leave the tokens of every Page lying in the session
    public const int SESSION_TTL = 600;

    private const string CSRF_TOKEN = 'social_meta_page';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
    ) {
    }

    // The Pages the account manages, each with the Instagram account Meta links to it, the one picked being the site's
    #[AdminRoute(path: '/social-meta-page', name: 'social_meta_page')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        $pages = $this->heldPages($request);
        if ([] === $pages) {
            $request->getSession()->remove(self::SESSION_PAGES);

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if (1 === \count($pages)) {
            $request->getSession()->remove(self::SESSION_PAGES);

            return $this->store($pages[0]);
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
                foreach ($pages as $page) {
                    if ($page['pageId'] === $request->request->getString('page')) {
                        $request->getSession()->remove(self::SESSION_PAGES);

                        return $this->store($page);
                    }
                }
            }

            // A token gone stale or a Page not in the list: said, rather than the screen coming back as if the click did nothing
            $this->addFlash('danger', t('flash.meta_page_invalid', [], 'social'));
        }

        return $this->render('@c975LSocial/management/social_meta_page.html.twig', [
            'pages' => $pages,
            'current' => trim((string) $this->configService->get('social-meta-page-id')),
            'token' => self::CSRF_TOKEN,
        ]);
    }

    // The Pages the callback held, unless held too long - an entry not of the shape this code writes (a session from another version of it) left out rather than failing the screen
    /** @return list<array{pageId: string, pageToken: string, instagramId: ?string}> */
    private function heldPages(Request $request): array
    {
        $held = $request->getSession()->get(self::SESSION_PAGES);
        if (!\is_array($held) || ($held['expires'] ?? 0) < time() || !\is_array($held['pages'] ?? null)) {
            return [];
        }

        return array_values(array_filter($held['pages'], $this->isPage(...)));
    }

    // The shape callback() writes, the keys store() reads
    private function isPage(mixed $page): bool
    {
        return \is_array($page) && \is_string($page['pageId'] ?? null) && \is_string($page['pageToken'] ?? null) && \array_key_exists('instagramId', $page);
    }

    // The Page the posts go out on, its token and the Instagram account linked to it
    /** @param array{pageId: string, pageToken: string, instagramId: ?string} $page */
    private function store(array $page): RedirectResponse
    {
        $this->configValueWriter->write([
            'social-meta-page-id' => $page['pageId'],
            'social-meta-page-token' => $page['pageToken'],
            'social-meta-instagram-id' => $page['instagramId'],
        ]);

        $this->addFlash('success', t(null === $page['instagramId'] ? 'flash.meta_connected_without_instagram' : 'flash.meta_connected', [], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }
}
