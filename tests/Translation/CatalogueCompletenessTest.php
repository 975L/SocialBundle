<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Translation;

use c975L\UiBundle\Testing\CatalogueCompletenessCase;

// Adding a language is adding its translation file, which only holds while every file of a domain ships the same keys
class CatalogueCompletenessTest extends CatalogueCompletenessCase
{
    protected static function translationsDirectory(): string
    {
        return \dirname(__DIR__, 2) . '/translations';
    }
}
