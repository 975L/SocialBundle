<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Whether this environment acts on the networks for real: a database imported locally or in staging holds production's tokens, the vault key being the same, and spending a single-use refresh token or posting there would end production's connection or post on its accounts
class ProductionGuard
{
    public function __construct(
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment = 'prod',
    ) {
    }

    // Whether single-use refresh tokens may be spent and planned posts sent from here
    public function isProduction(): bool
    {
        return 'prod' === $this->environment;
    }
}
