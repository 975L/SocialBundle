<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\ThemeColor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ThemeColorTest extends TestCase
{
    /** @return iterable<string, array{0: ?string, 1: ?array{0: int, 1: int, 2: int}}> */
    public static function notations(): iterable
    {
        yield 'long hex' => ['#ece5d8', [236, 229, 216]];
        yield 'short hex' => ['#000', [0, 0, 0]];
        yield 'rgb' => ['rgb(35, 56, 20)', [35, 56, 20]];
        yield 'rgb with odd spaces' => ['rgb(179, 0 ,0)', [179, 0, 0]];
        yield 'rgb without commas' => ['rgb(1 2 3)', [1, 2, 3]];
        yield 'another notation' => ['var(--primary)', null];
        yield 'none' => [null, null];
    }

    // Every notation a site's theme holds reads as three channels
    #[DataProvider('notations')]
    public function testAColourIsReadWhateverItsNotation(?string $value, ?array $expected): void
    {
        $this->assertSame($expected, ThemeColor::parse($value));
    }

    // Black on white gives the top of the WCAG scale, a colour on itself the bottom
    public function testContrastGoesFromOneToTwentyOne(): void
    {
        $this->assertEqualsWithDelta(21.0, ThemeColor::contrast([0, 0, 0], [255, 255, 255]), 0.01);
        $this->assertEqualsWithDelta(1.0, ThemeColor::contrast([12, 34, 56], [12, 34, 56]), 0.01);
    }
}
