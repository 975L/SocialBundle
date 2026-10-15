<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use EasyCorp\Bundle\EasyAdminBundle\Contracts\Context\AdminContextInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;

// The dashboard's admin context, absent outside a request
final class TemplateTestAdminContextProvider implements AdminContextProviderInterface
{
    // None, so icons fall back to FontAwesome with no prefix
    public function getContext(): ?AdminContextInterface
    {
        return null;
    }
}
