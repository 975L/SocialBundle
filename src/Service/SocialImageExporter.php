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
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\UiBundle\Model\SocialContent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The image of a content as the networks take it. Bluesky wants its bytes, Meta a public url it downloads itself - and Meta refuses WebP, the format the site's own images are stored in, Instagram even taking JPEG alone and within a range of ratios. So Meta gets a JPEG copy written under public/medias/social, framed rather than cropped: a panorama loses nothing of the picture, it gains bands in the site's background colour. The site's default image is no picture of the post: it gets the post's title and the site's name drawn beside it (see SocialStoryComposer). A site choosing "square" in "social-image-format" gets one 1080 x 1080 square on every network instead
class SocialImageExporter
{
    public const string DIRECTORY = 'medias/social';

    // What Instagram accepts at most, and more than any network shows
    private const int MAX_WIDTH = 1440;

    // The bands' colour on a site whose theme sets none, the one a photograph on a white wall is hung against
    private const array BAND_COLOR = [255, 255, 255];

    // The side of the one square every network gets, "social-image-format" set to "square"
    private const int SQUARE = 1080;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly string $projectDir,
        private readonly ConfigServiceInterface $configService,
        private readonly SocialStoryComposer $storyComposer,
    ) {
    }

    // The bytes a network uploads itself (Bluesky, LinkedIn): the picture as it is, or the site's square when it chose one, so every network shows the same visual
    public function bytes(SocialContent $content): ?string
    {
        $bytes = $this->read($content);

        return null === $bytes || !$this->isSquare() ? $bytes : $this->toJpeg($bytes, 1.0, 1.0, self::SQUARE);
    }

    // The image's bytes, read from disk when the content has it there, downloaded otherwise (a post prepared from a page's Open Graph tags) - null when there is none, or none that answers, and throwing on an address off the public web
    public function read(SocialContent $content): ?string
    {
        if (null !== $content->imagePath && is_file($content->imagePath)) {
            return (string) file_get_contents($content->imagePath);
        }

        if (null === $content->imageUrl) {
            return null;
        }

        $response = PublicWeb::get($this->httpClient, $content->imageUrl);

        return 200 === $response->getStatusCode() ? $response->getContent() : null;
    }

    // The public url of a JPEG copy whose width/height ratio lies between the two given (see toJpeg() for its rounding), null when the content has no image or the site no "site-url" to serve it from. Written once per image and range, the next post of the same picture finding it there
    public function jpegUrl(SocialContent $content, float $minRatio = 0.0, float $maxRatio = \PHP_FLOAT_MAX): ?string
    {
        // The site's default image says nothing of the post: it goes out with the post's title and the site's name, framed as plainly when the title has no font to be drawn with
        if (self::isDefaultImage($content) && '' !== trim($content->title)) {
            try {
                return $this->titledUrl($content);
            } catch (\RuntimeException) {
            }
        }

        // The square stands within every range a network accepts, Instagram's included
        $square = $this->isSquare();

        return $square ? $this->writeJpeg($content, 1.0, 1.0, self::SQUARE) : $this->writeJpeg($content, $minRatio, $maxRatio, null);
    }

    // The public url of the content's image as a story, drawn with its title and the site's name (see SocialStoryComposer) - null as jpegUrl() is
    public function storyUrl(SocialContent $content): ?string
    {
        return $this->write($content, 'story' . $this->storyComposer->signature($content->title) . implode(',', $this->bandColor()), fn (string $bytes): string => $this->encoded($this->storyComposer->compose($bytes, $content->title, $this->bandColor())));
    }

    // Whether a content's image is the site's default one, the fixed "og-image" at the root of public/ a content without a picture of its own falls back to
    public static function isDefaultImage(SocialContent $content): bool
    {
        $file = $content->imagePath ?? (string) parse_url((string) $content->imageUrl, \PHP_URL_PATH);

        return 'og-image' === pathinfo($file, \PATHINFO_FILENAME);
    }

    // The default image drawn 4:5 with the post's title and the site's name, square on a site choosing it
    private function titledUrl(SocialContent $content): ?string
    {
        $height = $this->isSquare() ? self::SQUARE : SocialStoryComposer::POST_HEIGHT;

        return $this->write($content, 'post' . $height . $this->storyComposer->signature($content->title) . implode(',', $this->bandColor()), fn (string $bytes): string => $this->encoded($this->storyComposer->composePost($bytes, $content->title, $this->bandColor(), $height)));
    }

    // Writes the JPEG copy once per image, range and width, answering its public url
    private function writeJpeg(SocialContent $content, float $minRatio, float $maxRatio, ?int $width): ?string
    {
        return $this->write($content, $minRatio . $maxRatio . $width . implode(',', $this->bandColor()), fn (string $bytes): string => $this->toJpeg($bytes, $minRatio, $maxRatio, $width));
    }

    // Writes a JPEG once per image and $variant, answering its public url
    /** @param callable(string): string $jpeg */
    private function write(SocialContent $content, string $variant, callable $jpeg): ?string
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        $bytes = null === $siteUrl ? null : $this->read($content);
        if (null === $bytes) {
            return null;
        }

        $name = self::DIRECTORY . '/' . sha1($bytes . $variant) . '.jpg';
        $path = $this->projectDir . '/public/' . $name;
        if (!is_file($path)) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0o775, true);
            }
            // Written aside then renamed, so Meta and Pinterest never download a file cut short
            $temporary = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
            file_put_contents($temporary, $jpeg($bytes));
            rename($temporary, $path);
        }

        return $siteUrl . '/' . $name;
    }

    private function isSquare(): bool
    {
        return 'square' === $this->configService->get('social-image-format');
    }

    // The site's own background ("theme-color-background"), white when its theme sets none or another notation
    /** @return array{0: int, 1: int, 2: int} */
    private function bandColor(): array
    {
        return ThemeColor::parse($this->configService->get('theme-color-background')) ?? self::BAND_COLOR;
    }

    // Draws the picture centred on a canvas stretched to the nearest accepted ratio, on the site's background, then scaled down to MAX_WIDTH - or, $size given, to that width. The canvas is rounded towards the wider: the lowest ratio is held exactly, Instagram's being the one its pictures are framed to, the highest within a pixel
    private function toJpeg(string $bytes, float $minRatio, float $maxRatio, ?int $size = null): string
    {
        $source = imagecreatefromstring($bytes);
        if (false === $source) {
            throw new \RuntimeException('The image of this post could not be read.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = $width / $height;
        $canvasWidth = $ratio < $minRatio ? (int) ceil($height * $minRatio) : $width;
        $canvasHeight = $ratio > $maxRatio ? (int) floor($width / $maxRatio) : $height;
        $scale = null === $size ? min(1, self::MAX_WIDTH / $canvasWidth) : $size / $canvasWidth;

        $canvas = imagecreatetruecolor(max(1, (int) round($canvasWidth * $scale)), max(1, (int) round($canvasHeight * $scale)));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, ...$this->bandColor()));
        imagecopyresampled(
            $canvas,
            $source,
            (int) round(($canvasWidth - $width) / 2 * $scale),
            (int) round(($canvasHeight - $height) / 2 * $scale),
            0,
            0,
            (int) round($width * $scale),
            (int) round($height * $scale),
            $width,
            $height,
        );

        return $this->encoded($canvas);
    }

    // A drawn picture as JPEG bytes
    private function encoded(\GdImage $canvas): string
    {
        ob_start();
        imagejpeg($canvas, null, 90);

        return (string) ob_get_clean();
    }
}
