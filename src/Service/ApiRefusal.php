<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Contracts\HttpClient\ResponseInterface;

// A network explains a refusal in the body ("Board not found", "Not enough permissions", "quotaExceeded"), which is what the screen should show rather than a bare status code - each network putting the message at a place of its own
class ApiRefusal
{
    // Throws when the response is a refusal, its message read at $messagePath (dot-separated), the status code carried as the exception's code
    public static function throwIf(ResponseInterface $response, string $network, string $what, string $messagePath = 'message'): void
    {
        $status = $response->getStatusCode();
        if ($status < 300) {
            return;
        }

        $message = json_decode($response->getContent(false), true);
        foreach (explode('.', $messagePath) as $key) {
            $message = \is_array($message) ? ($message[$key] ?? null) : null;
        }

        throw new \RuntimeException(sprintf('%s refused %s: %s', $network, $what, \is_scalar($message) ? $message : $status), $status);
    }
}
