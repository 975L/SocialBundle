<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\StylesheetProvider;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class StylesheetProviderTest extends TestCase
{
    // The bundle contributes exactly its own minified stylesheet, published under public/bundles/c975lsocial
    public function testGetStylesheetsReturnsMinifiedBundleCss(): void
    {
        $provider = new StylesheetProvider();

        $this->assertSame(['bundles/c975lsocial/css/styles.min.css'], $provider->getStylesheets());
    }
}
