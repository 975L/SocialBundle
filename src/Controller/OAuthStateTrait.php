<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller;

use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

use function Symfony\Component\Translation\t;

// The state an OAuth consent carries out and back, held in the session under the controller's SESSION_STATE: without it the callback would accept a code obtained by anyone who can make the editor's browser follow a link - and the callback's common path, from that code to the connection it brings
trait OAuthStateTrait
{
    // A new state, held until the callback
    private function newState(Request $request): string
    {
        $state = bin2hex(random_bytes(16));
        $request->getSession()->set(self::SESSION_STATE, $state);

        return $state;
    }

    // The code the callback received, null when the state is not the one held or no code came back - the held state spent either way
    private function callbackCode(Request $request): ?string
    {
        $expected = $request->getSession()->remove(self::SESSION_STATE);
        $code = $request->query->get('code');

        return \is_string($expected) && $expected === $request->query->get('state') && \is_string($code) && '' !== $code ? $code : null;
    }

    // The connection the callback's code is traded for, fetched before anything is written so a failure on a reconnection leaves the working connection as it was - back to "Connexions" with the refusal or the network's own message otherwise
    /**
     * @template T of array
     *
     * @param callable(string): T $connect
     *
     * @return T|RedirectResponse
     */
    private function callbackConnection(Request $request, string $refusedKey, callable $connect): array | RedirectResponse
    {
        $code = $this->callbackCode($request);
        if (null === $code) {
            $this->addFlash('danger', t($refusedKey, [], 'social'));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }

        try {
            return $connect($code);
        } catch (\Throwable $exception) {
            $this->addFlash('danger', htmlspecialchars($exception->getMessage()));

            return $this->redirectToRoute(SocialConnectionsController::ROUTE);
        }
    }
}
