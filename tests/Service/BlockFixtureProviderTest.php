<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\BlockFixtureProvider;
use PHPUnit\Framework\TestCase;

class BlockFixtureProviderTest extends TestCase
{
    // "social_links" is pickable: false (never shown); "social_links_display" is covered by
    // GalleryShowcaseProvider instead (see its own class comment for why) - neither needs a fixture here
    public function testGetFixturesReturnsNothing(): void
    {
        $this->assertSame([], (new BlockFixtureProvider())->getFixtures());
    }
}
