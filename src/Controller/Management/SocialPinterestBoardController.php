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
use c975L\SocialBundle\Service\PinterestClient;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

// The board the pins go on, read from the connected account each time rather than held in the session as LinkedIn's Pages are (see SocialLinkedInPageController): the tokens are already stored, the board alone being left to pick. A single board is taken at once, none sends the owner to create one on Pinterest
class SocialPinterestBoardController extends AbstractController
{
    // The dashboard prefixes the AdminRoute name with its own route name (see SocialConnectionsController::ROUTE)
    public const string ROUTE = 'management_social_pinterest_board';

    private const string CSRF_TOKEN = 'social_pinterest_board';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigValueWriter $configValueWriter,
        private readonly PinterestClient $pinterestClient,
    ) {
    }

    // The account's public boards, each opened on Pinterest, the one picked being where the pins go
    #[AdminRoute(path: '/social-pinterest-board', name: 'social_pinterest_board')]
    public function index(Request $request): Response
    {
        $this->denyAccessUnlessGranted($this->configService->get('site-role-editor'));

        if (!$this->pinterestClient->isConnected()) {
            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        try {
            $boards = $this->pinterestClient->boards();
        } catch (\RuntimeException $exception) {
            $this->addFlash('danger', $exception->getMessage());

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if ([] === $boards) {
            $this->addFlash('warning', t('flash.pinterest_no_board', [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        if (1 === \count($boards)) {
            return $this->store($boards[0]);
        }

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN, $request->request->getString('token'))) {
                foreach ($boards as $board) {
                    if ($board['id'] === $request->request->getString('board')) {
                        return $this->store($board);
                    }
                }
            }

            // A token gone stale or a board not in the list: said, rather than the screen coming back as if the click did nothing
            $this->addFlash('danger', t('flash.pinterest_board_invalid', [], 'social'));
        }

        return $this->render('@c975LSocial/management/social_pinterest_board.html.twig', [
            'boards' => $boards,
            'username' => trim((string) $this->configService->get('social-pinterest-username')),
            'current' => trim((string) $this->configService->get('social-pinterest-board-id')),
            'token' => self::CSRF_TOKEN,
        ]);
    }

    // The board picked, its name kept for "Connexions" to show
    /** @param array{id: string, name: string} $board */
    private function store(array $board): RedirectResponse
    {
        $this->configValueWriter->write(['social-pinterest-board-id' => $board['id'], 'social-pinterest-board-name' => $board['name']]);
        $this->addFlash('success', t('flash.pinterest_board_picked', ['%name%' => $board['name']], 'social'));

        return $this->redirectToRoute(SocialConnectionsController::ROUTE);
    }
}
