<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\SocialFontProvider;
use c975L\SocialBundle\Service\SocialStoryComposer;
use PHPUnit\Framework\TestCase;

class SocialStoryComposerTest extends TestCase
{
    private const string FONT = __DIR__ . '/../Fixtures/story-font.ttf';
    private const array BACKGROUND = [236, 229, 216];
    private const array PICTURE = [200, 30, 30];
    private const array PRIMARY = [35, 56, 20];

    // A composer whose title font is given, the theme overridable
    /** @param array<string, ?string> $config */
    private function composer(?string $titleFont = self::FONT, ?string $bodyFont = self::FONT, array $config = []): SocialStoryComposer
    {
        $fontProvider = $this->createStub(SocialFontProvider::class);
        $fontProvider->method('path')->willReturnCallback(static fn (string $family): ?string => 'Title Font' === $family ? $titleFont : $bodyFont);
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key): ?string => ($config + [
            'site-name' => 'Site Name',
            'theme-font-family-title' => 'Title Font',
            'theme-font-family-body' => 'Body Font',
            'theme-color-primary' => 'rgb(35, 56, 20)',
            'theme-color-text' => '#1a1c22',
        ])[$key] ?? null);

        return new SocialStoryComposer($fontProvider, $configService);
    }

    // A plain picture's bytes
    private function picture(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, ...self::PICTURE));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    // The rows of a canvas holding a colour, as first and last row
    /**
     * @param array{0: int, 1: int, 2: int} $color
     *
     * @return ?array{0: int, 1: int}
     */
    private function rowsOf(\GdImage $canvas, array $color): ?array
    {
        $rows = [];
        for ($y = 0; $y < imagesy($canvas); ++$y) {
            for ($x = 0; $x < imagesx($canvas); $x += 3) {
                if ($this->isColor($canvas, $x, $y, $color)) {
                    $rows[] = $y;
                    break;
                }
            }
        }

        return [] === $rows ? null : [$rows[0], $rows[\count($rows) - 1]];
    }

    // Whether a colour reaches past the right margin, where a text too wide gets cut by the canvas
    /** @param array{0: int, 1: int, 2: int} $color */
    private function runsPastTheMargin(\GdImage $canvas, array $color): bool
    {
        for ($y = 0; $y < imagesy($canvas); ++$y) {
            for ($x = 1030; $x < imagesx($canvas); ++$x) {
                if ($this->isColor($canvas, $x, $y, $color)) {
                    return true;
                }
            }
        }

        return false;
    }

    // Whether a pixel holds exactly a colour
    /** @param array{0: int, 1: int, 2: int} $color */
    private function isColor(\GdImage $canvas, int $x, int $y, array $color): bool
    {
        $pixel = imagecolorat($canvas, $x, $y);

        return [($pixel >> 16) & 255, ($pixel >> 8) & 255, $pixel & 255] === $color;
    }

    // The title sits above the picture, the site's name below it, all clear of what Instagram lays over the screen
    public function testTheTextsSurroundThePictureInTheSafeZone(): void
    {
        $canvas = $this->composer()->compose($this->picture(1600, 1103), 'Item title', self::BACKGROUND);

        $this->assertSame([1080, 1920], [imagesx($canvas), imagesy($canvas)]);
        [$pictureTop, $pictureBottom] = $this->rowsOf($canvas, self::PICTURE) ?? [0, 0];
        [$textTop] = $this->rowsOf($canvas, self::PRIMARY) ?? [0];
        $this->assertGreaterThanOrEqual(250, $textTop);
        $this->assertLessThan($pictureTop, $textTop);
        $this->assertLessThanOrEqual(1580, $pictureBottom);
        $this->assertGreaterThan(700, $pictureBottom - $pictureTop);
        $this->assertNotNull($this->rowsOf($canvas, [0x1A, 0x1C, 0x22]));
    }

    // A tall picture gives way to the texts rather than running under Instagram's interface
    public function testAPortraitLeavesRoomForTheTexts(): void
    {
        $canvas = $this->composer()->compose($this->picture(700, 1000), 'Item title', self::BACKGROUND);

        [$pictureTop, $pictureBottom] = $this->rowsOf($canvas, self::PICTURE) ?? [0, 0];
        $this->assertGreaterThan(250, $pictureTop);
        $this->assertLessThan(1580, $pictureBottom);
    }

    // A picture already filling the screen keeps it, its texts in a band of the site's background clear of Instagram's interface
    public function testAPictureFillingTheScreenTakesItsTextsInABand(): void
    {
        $canvas = $this->composer()->compose($this->picture(768, 1352), 'Item title', self::BACKGROUND);

        [$pictureTop, $pictureBottom] = $this->rowsOf($canvas, self::PICTURE) ?? [0, 0];
        $this->assertLessThan(15, $pictureTop);
        $this->assertGreaterThan(1905, $pictureBottom);
        [$textTop, $textBottom] = $this->rowsOf($canvas, self::PRIMARY) ?? [0, 0];
        $this->assertGreaterThanOrEqual(250, $textTop);
        $this->assertLessThan(700, $textBottom);
        $this->assertTrue($this->isColor($canvas, 540, 255, self::BACKGROUND));
        $this->assertNotNull($this->rowsOf($canvas, [0x1A, 0x1C, 0x22]));
    }

    // A filling picture needs its title as any other: no font refuses the story
    public function testAPictureFillingTheScreenNeedsItsFontToo(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->composer(titleFont: null)->compose($this->picture(768, 1352), 'Item title', self::BACKGROUND);
    }

    // A content without a title has nothing to write: the picture alone, no font asked for
    public function testAnEmptyTitleLeavesThePictureAlone(): void
    {
        $canvas = $this->composer(titleFont: null)->compose($this->picture(1600, 1103), ' ', self::BACKGROUND);

        $this->assertNull($this->rowsOf($canvas, self::PRIMARY));
        $this->assertNotNull($this->rowsOf($canvas, self::PICTURE));
    }

    // Without its title a story says nothing: it is refused, with the font's name
    public function testATitleFontOutOfGoogleFontsRefusesTheStory(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('"Title Font"');

        $this->composer(titleFont: null)->compose($this->picture(1600, 1103), 'Item title', self::BACKGROUND);
    }

    // A body font Google Fonts does not have gives way to the title's
    public function testABodyFontOutOfGoogleFontsGivesWayToTheTitles(): void
    {
        $canvas = $this->composer(bodyFont: null)->compose($this->picture(1600, 1103), 'Item title', self::BACKGROUND);

        $this->assertNotNull($this->rowsOf($canvas, [0x1A, 0x1C, 0x22]));
    }

    // A primary colour that does not read on a dark background gives way to the theme's text
    public function testAColourThatDoesNotReadGivesWayToTheText(): void
    {
        $composer = $this->composer(config: ['theme-color-primary' => 'rgb(28, 87, 140)', 'theme-color-text' => '#f6ecd9']);

        $canvas = $composer->compose($this->picture(1600, 1103), 'Item title', [12, 31, 51]);

        $this->assertNull($this->rowsOf($canvas, [28, 87, 140]));
        $this->assertNotNull($this->rowsOf($canvas, [0xF6, 0xEC, 0xD9]));
    }

    // No readable colour in the theme at all falls back to white on a dark background
    public function testNoReadableColourFallsBackToBlackOrWhite(): void
    {
        $composer = $this->composer(config: ['theme-color-primary' => null, 'theme-color-text' => null]);

        $canvas = $composer->compose($this->picture(1600, 1103), 'Item title', [12, 31, 51]);

        $this->assertNotNull($this->rowsOf($canvas, [255, 255, 255]));
    }

    // A site without a name still gets its title
    public function testASiteWithoutNameKeepsItsTitle(): void
    {
        $canvas = $this->composer(config: ['site-name' => ''])->compose($this->picture(1600, 1103), 'Item title', self::BACKGROUND);

        $this->assertNotNull($this->rowsOf($canvas, self::PRIMARY));
        $this->assertNull($this->rowsOf($canvas, [0x1A, 0x1C, 0x22]));
    }

    // A title cut by its post ends with three dots, the ellipsis being blank in some fonts
    public function testAnEllipsisIsDrawnAsThreeDots(): void
    {
        $composer = $this->composer();
        $picture = $this->picture(1600, 1103);

        ob_start();
        imagepng($composer->compose($picture, 'Item title…', self::BACKGROUND));
        $withEllipsis = ob_get_clean();
        ob_start();
        imagepng($composer->compose($picture, 'Item title...', self::BACKGROUND));

        $this->assertSame(ob_get_clean(), $withEllipsis);
    }

    // A very long title stays within the picture's width and above it, cut rather than running on
    public function testAVeryLongTitleStaysAboveThePicture(): void
    {
        $canvas = $this->composer()->compose($this->picture(1600, 1103), str_repeat('Item title long enough ', 30), self::BACKGROUND);

        [$pictureTop] = $this->rowsOf($canvas, self::PICTURE) ?? [0];
        [$textTop] = $this->rowsOf($canvas, self::PRIMARY) ?? [0];
        $this->assertGreaterThanOrEqual(250, $textTop);
        $this->assertLessThan($pictureTop, $textTop);
    }

    // A long word gets a smaller size rather than running off the screen on a single line, and one no size holds is cut
    public function testALongWordStaysWithinTheMargins(): void
    {
        foreach ([12, 80] as $length) {
            $canvas = $this->composer()->compose($this->picture(1600, 1103), str_repeat('m', $length), self::BACKGROUND);

            $this->assertFalse($this->runsPastTheMargin($canvas, self::PRIMARY));
            $this->assertNotNull($this->rowsOf($canvas, self::PRIMARY));
        }
    }

    // A site's name too long for the width is cut within the margins
    public function testALongSiteNameStaysWithinTheMargins(): void
    {
        $canvas = $this->composer(config: ['site-name' => str_repeat('m', 80)])->compose($this->picture(1600, 1103), 'Item title', self::BACKGROUND);

        $this->assertFalse($this->runsPastTheMargin($canvas, [0x1A, 0x1C, 0x22]));
    }

    // An unreadable file refuses the story as a post's picture would
    public function testAnUnreadablePictureIsRefused(): void
    {
        $this->expectException(\RuntimeException::class);

        @$this->composer()->compose('not a picture', 'Item title', self::BACKGROUND);
    }

    // A new title or theme writes a new file
    public function testTheSignatureFollowsTheTitleAndTheTheme(): void
    {
        $composer = $this->composer();

        $this->assertNotSame($composer->signature('One'), $composer->signature('Two'));
        $this->assertNotSame($composer->signature('One'), $this->composer(config: ['theme-color-primary' => '#000'])->signature('One'));
    }
}
