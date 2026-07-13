<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\ScriptProvider;
use PHPUnit\Framework\TestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class ScriptProviderTest extends TestCase
{
    // The front-office Stimulus controller must be advertised under its AssetMapper import name
    public function testGetScriptsReturnsFrontControllersAsset(): void
    {
        $provider = new ScriptProvider();

        $this->assertSame(['@c975l/social-bundle/controllers.js'], $provider->getScripts());
    }

    // The admin Stimulus controller (dashboard forms) is a distinct asset from the front one
    public function testGetAdminScriptsReturnsAdminControllersAsset(): void
    {
        $provider = new ScriptProvider();

        $this->assertSame(['@c975l/social-bundle/controllers-admin.js'], $provider->getAdminScripts());
    }
}
