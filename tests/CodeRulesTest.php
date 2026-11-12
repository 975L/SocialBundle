<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests;

use c975L\ConfigBundle\Test\CodeRulesTestCase;

// The audit's rules checked on the bundle's code and templates
class CodeRulesTest extends CodeRulesTestCase
{
    // The bundle's sources
    protected function sourceDirectory(): string
    {
        return __DIR__ . '/../src';
    }

    // The namespace its sources are under
    protected function sourceNamespace(): string
    {
        return 'c975L\SocialBundle';
    }
}
