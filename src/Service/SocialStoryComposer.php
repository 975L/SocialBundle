<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;

// A story's picture, 9:16 at 1080 x 1920 as Meta recommends it, or a post's one drawn from the site's default image, 4:5 at 1080 x 1350 or the site's square. The API taking no text on a story, the post's title and the site's name are drawn in the picture, in the theme's fonts and colours and clear of what Instagram lays over the top and bottom of the screen: around the picture, or in a band across one already filling the canvas
class SocialStoryComposer
{
    // A post's height, 4:5 being the lowest ratio Instagram takes
    public const int POST_HEIGHT = 1350;

    private const int WIDTH = 1080;
    private const int HEIGHT = 1920;
    private const int SAFE_TOP = 250;
    private const int SAFE_BOTTOM = 1580;
    private const int MARGIN = 65;
    private const int GAP = 43;
    private const int TEXT_WIDTH = self::WIDTH - 2 * self::MARGIN;

    // How much wider than the canvas a picture may be and still fill it, its texts then going in a band across it: 0.6 on a story
    private const float FULL_SCREEN_SLACK = 0.6 * self::HEIGHT / self::WIDTH;

    // The title's sizes in points, the first holding it in three lines within the margins being kept
    private const array TITLE_SIZES = [89, 75, 63, 54];
    private const int TITLE_MAX_LINES = 3;
    private const int NAME_SIZE = 40;
    private const float LINE_HEIGHT = 1.08;

    // What contrast a title needs to read on the site's background, the WCAG's for large text
    private const float MIN_CONTRAST = 3.0;

    public function __construct(
        private readonly SocialFontProvider $fontProvider,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // What changes the story of a picture, naming its file so that a new title or theme writes a new one
    public function signature(string $title): string
    {
        return implode('|', [$title, ...array_map(fn (string $key): string => (string) $this->configService->get($key), ['site-name', 'theme-font-family-title', 'theme-font-family-body', 'theme-color-primary', 'theme-color-text'])]);
    }

    // The picture on the site's background with the title and the site's name, throwing when the title has no font to be drawn with: a story without it says nothing
    /** @param array{0: int, 1: int, 2: int} $background */
    public function compose(string $bytes, string $title, array $background): \GdImage
    {
        return $this->draw($bytes, $title, $background, self::HEIGHT, self::SAFE_TOP, self::SAFE_BOTTOM);
    }

    // A post's picture $height high, 4:5 by default, with the title and the site's name within the margins, nothing of a feed laid over it
    /** @param array{0: int, 1: int, 2: int} $background */
    public function composePost(string $bytes, string $title, array $background, int $height = self::POST_HEIGHT): \GdImage
    {
        return $this->draw($bytes, $title, $background, $height, self::MARGIN, $height - self::MARGIN);
    }

    // The picture on a canvas $height high, its texts kept between $safeTop and $safeBottom
    /** @param array{0: int, 1: int, 2: int} $background */
    private function draw(string $bytes, string $title, array $background, int $height, int $safeTop, int $safeBottom): \GdImage
    {
        $source = imagecreatefromstring($bytes);
        if (false === $source) {
            throw new \RuntimeException('The image of this post could not be read.');
        }

        $canvas = imagecreatetruecolor(self::WIDTH, $height);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, ...$background));

        // Three dots rather than the ellipsis a title cut by SocialPost ends with, a glyph some fonts draw blank
        $title = str_replace('…', '...', trim($title));

        if ('' === $title) {
            $this->drawPicture($canvas, $source, 0, $height);
        } elseif (imagesx($source) / imagesy($source) < self::WIDTH / $height * self::FULL_SCREEN_SLACK) {
            $this->drawWithBand($canvas, $source, $title, $background, $safeTop);
        } else {
            $this->drawWithTexts($canvas, $source, $title, $background, $safeTop, $safeBottom);
        }

        return $canvas;
    }

    // The picture over the whole canvas, the title and the site's name in a band of the site's background across the top of the safe zone
    /** @param array{0: int, 1: int, 2: int} $background */
    private function drawWithBand(\GdImage $canvas, \GdImage $source, string $title, array $background, int $safeTop): void
    {
        $this->drawPicture($canvas, $source, 0, imagesy($canvas));

        $titleFont = $this->titleFont();
        $name = trim((string) $this->configService->get('site-name'));
        [$size, $lines] = $this->fitTitle($title, $titleFont);
        $titleHeight = \count($lines) * $this->lineHeight($size);
        $nameHeight = '' === $name ? 0 : $this->lineHeight(self::NAME_SIZE);
        $bandBottom = $safeTop + 2 * self::GAP + $titleHeight + $nameHeight;

        imagefilledrectangle($canvas, 0, $safeTop, self::WIDTH, $bandBottom, (int) imagecolorallocate($canvas, ...$background));
        $this->drawTitle($canvas, $background, $lines, $size, $safeTop + self::GAP, $titleFont);
        $this->drawName($canvas, $background, $name, $safeTop + self::GAP + $titleHeight, $titleFont);
    }

    // The title, the picture and the site's name stacked in the middle of the safe zone, the picture taking the height the texts leave it
    /** @param array{0: int, 1: int, 2: int} $background */
    private function drawWithTexts(\GdImage $canvas, \GdImage $source, string $title, array $background, int $safeTop, int $safeBottom): void
    {
        $titleFont = $this->titleFont();
        $name = trim((string) $this->configService->get('site-name'));
        [$size, $lines] = $this->fitTitle($title, $titleFont);

        $titleHeight = \count($lines) * $this->lineHeight($size);
        $nameHeight = '' === $name ? 0 : self::GAP + $this->lineHeight(self::NAME_SIZE);
        $safeHeight = $safeBottom - $safeTop;
        $pictureRoom = $safeHeight - $titleHeight - self::GAP - $nameHeight;
        $pictureHeight = min($pictureRoom, (int) round(imagesy($source) * self::WIDTH / imagesx($source)));
        $top = $safeTop + (int) (($safeHeight - $titleHeight - self::GAP - $pictureHeight - $nameHeight) / 2);

        $this->drawTitle($canvas, $background, $lines, $size, $top, $titleFont);

        $pictureTop = $top + $titleHeight + self::GAP;
        $this->drawPicture($canvas, $source, $pictureTop, $pictureHeight);
        $this->drawName($canvas, $background, $name, $pictureTop + $pictureHeight + self::GAP, $titleFont);
    }

    // The title's lines from $top, in the theme's main colour
    /**
     * @param array{0: int, 1: int, 2: int} $background
     * @param list<string>                  $lines
     */
    private function drawTitle(\GdImage $canvas, array $background, array $lines, int $size, int $top, string $font): void
    {
        $color = $this->readableColor($canvas, $background, 'theme-color-primary');
        foreach ($lines as $rank => $line) {
            $this->drawLine($canvas, $size, $color, $top + $rank * $this->lineHeight($size), $font, $line);
        }
    }

    // The site's name at $top in the theme's text colour, nothing for a site without one
    /** @param array{0: int, 1: int, 2: int} $background */
    private function drawName(\GdImage $canvas, array $background, string $name, int $top, string $titleFont): void
    {
        if ('' !== $name) {
            $font = $this->nameFont($titleFont);
            $this->drawLine($canvas, self::NAME_SIZE, $this->readableColor($canvas, $background, 'theme-color-text'), $top, $font, $this->heldToWidth($name, $font, self::NAME_SIZE));
        }
    }

    // The picture centred across the width at $top, as large as $height and the width let it
    private function drawPicture(\GdImage $canvas, \GdImage $source, int $top, int $height): void
    {
        $scale = min(self::WIDTH / imagesx($source), $height / imagesy($source));
        $width = (int) round(imagesx($source) * $scale);
        $drawnHeight = (int) round(imagesy($source) * $scale);

        imagecopyresampled($canvas, $source, (int) ((self::WIDTH - $width) / 2), $top + (int) (($height - $drawnHeight) / 2), 0, 0, $width, $drawnHeight, imagesx($source), imagesy($source));
    }

    // A line of text whose box starts at $top, left aligned on the margin
    private function drawLine(\GdImage $canvas, int $size, int $color, int $top, string $font, string $text): void
    {
        imagettftext($canvas, $size, 0, self::MARGIN, $top + (int) round($size * 96 / 72), $color, $font, $text);
    }

    // The largest size holding the title in three lines within the margins, the smallest one cutting what still goes past them
    /** @return array{0: int, 1: list<string>} */
    private function fitTitle(string $title, string $font): array
    {
        $size = self::TITLE_SIZES[0];
        $lines = [];
        foreach (self::TITLE_SIZES as $size) {
            $lines = $this->wrap($title, $font, $size);
            if (\count($lines) <= self::TITLE_MAX_LINES && array_all($lines, fn (string $line): bool => $this->width($line, $font, $size) <= self::TEXT_WIDTH)) {
                return [$size, $lines];
            }
        }

        $kept = \array_slice($lines, 0, self::TITLE_MAX_LINES);
        if (\count($lines) > self::TITLE_MAX_LINES) {
            $kept[self::TITLE_MAX_LINES - 1] .= '...';
        }

        return [$size, array_map(fn (string $line): string => $this->heldToWidth($line, $font, $size), $kept)];
    }

    // A line kept within the margins, an ellipsis saying what was cut
    private function heldToWidth(string $line, string $font, int $size): string
    {
        while ($this->width($line, $font, $size) > self::TEXT_WIDTH) {
            $line = rtrim(mb_substr($line, 0, -4)) . '...';
        }

        return $line;
    }

    // The text cut into the lines the width between the margins holds, a word never being split
    /** @return list<string> */
    private function wrap(string $text, string $font, int $size): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/', $text) ?: [] as $word) {
            $candidate = '' === $line ? $word : $line . ' ' . $word;
            if ('' !== $line && $this->width($candidate, $font, $size) > self::TEXT_WIDTH) {
                $lines[] = $line;
                $candidate = $word;
            }
            $line = $candidate;
        }
        $lines[] = $line;

        return $lines;
    }

    // How wide a text is drawn, in pixels
    private function width(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return false === $box ? 0 : $box[2] - $box[0];
    }

    // How high a line of a size is drawn, in pixels
    private function lineHeight(int $size): int
    {
        return (int) round($size * 96 / 72 * self::LINE_HEIGHT);
    }

    // The font of the site's titles, without which there is no story to make
    private function titleFont(): string
    {
        $family = (string) $this->configService->get('theme-font-family-title');

        return $this->fontProvider->path($family) ?? throw new \RuntimeException(sprintf('A story writes its title in the font of the site\'s titles, and "%s" is not on Google Fonts.', $family));
    }

    // The font of the site's texts for its name, the titles' one when Google Fonts does not have it
    private function nameFont(string $titleFont): string
    {
        return $this->fontProvider->path((string) $this->configService->get('theme-font-family-body')) ?? $titleFont;
    }

    // The theme's colour when it reads on the background, the theme's text otherwise, black or white failing that
    /** @param array{0: int, 1: int, 2: int} $background */
    private function readableColor(\GdImage $canvas, array $background, string $key): int
    {
        foreach ([$key, 'theme-color-text'] as $candidate) {
            $color = ThemeColor::parse($this->configService->get($candidate));
            if (null !== $color && ThemeColor::contrast($color, $background) >= self::MIN_CONTRAST) {
                return (int) imagecolorallocate($canvas, ...$color);
            }
        }

        $level = ThemeColor::contrast([0, 0, 0], $background) >= ThemeColor::contrast([255, 255, 255], $background) ? 0 : 255;

        return (int) imagecolorallocate($canvas, $level, $level, $level);
    }
}
