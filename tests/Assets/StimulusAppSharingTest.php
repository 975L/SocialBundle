<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Assets;

use PHPUnit\Framework\TestCase;

// One Stimulus application per page: startStimulusApp() also registers whatever the consuming app's controllers.json enables, so barrels each starting their own built the live controller once per barrel and a Live Component answered as many times
class StimulusAppSharingTest extends TestCase
{
    public function testEveryBarrelJoinsTheSharedApplication(): void
    {
        $barrels = $this->barrels();

        foreach ($barrels as $barrel) {
            $source = (string) file_get_contents($barrel);
            $name = basename($barrel);

            $this->assertStringContainsString(
                'globalThis.c975lStimulusApp ??= startStimulusApp()',
                $source,
                sprintf('"%s" starts an application of its own instead of joining the page\'s.', $name)
            );

            // The guarded line may be there and a bare call added below it, which would start a second application anyway. The import statement carries no parentheses, so the count is exact
            $this->assertSame(
                1,
                substr_count($source, 'startStimulusApp()'),
                sprintf('"%s" calls startStimulusApp() more than once.', $name)
            );
        }
    }

    // Swept rather than listed, so a barrel added later is checked without anyone having to remember this test
    private function barrels(): array
    {
        $barrels = glob(\dirname(__DIR__, 2) . '/assets/controllers*.js') ?: [];
        $this->assertGreaterThanOrEqual(2, count($barrels), 'The barrels were not found where they are expected.');

        return $barrels;
    }
}
