<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller;

use Symfony\Component\HttpFoundation\Request;

// The state an OAuth consent carries out and back, held in the session under the controller's SESSION_STATE: without it the callback would accept a code obtained by anyone who can make the editor's browser follow a link
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
}
