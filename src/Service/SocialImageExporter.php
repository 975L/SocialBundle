<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\UiBundle\Model\SocialContent;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// The image of a content as the networks take it. Bluesky wants its bytes, Meta a public url it downloads itself - and Meta refuses WebP, the format the site's own images are stored in, Instagram even taking JPEG alone and within a range of ratios. So Meta gets a JPEG copy written under public/medias/social, framed rather than cropped: a panorama loses nothing of the picture, it gains bands
class SocialImageExporter
{
    public const string DIRECTORY = 'medias/social';

    // What Instagram accepts at most, and more than any network shows
    private const int MAX_WIDTH = 1440;

    // The bands' colour, the one a photograph on a white wall is hung against
    private const array BAND_COLOR = [255, 255, 255];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SiteUrlResolver $siteUrlResolver,
        private readonly string $projectDir,
    ) {
    }

    // The image's bytes, read from disk when the content has it there, downloaded otherwise (a post prepared from a page's Open Graph tags) - null when there is none, or none that answers
    public function read(SocialContent $content): ?string
    {
        if (null !== $content->imagePath && is_file($content->imagePath)) {
            return (string) file_get_contents($content->imagePath);
        }

        if (null === $content->imageUrl) {
            return null;
        }

        $response = $this->httpClient->request('GET', $content->imageUrl, ['timeout' => 30]);

        return 200 === $response->getStatusCode() ? $response->getContent() : null;
    }

    // The public url of a JPEG copy whose width/height ratio lies between the two given, null when the content has no image or the site no "site-url" to serve it from. Written once per image and range, the next post of the same picture finding it there
    public function jpegUrl(SocialContent $content, float $minRatio = 0.0, float $maxRatio = \PHP_FLOAT_MAX): ?string
    {
        $siteUrl = $this->siteUrlResolver->siteUrl();
        $bytes = null === $siteUrl ? null : $this->read($content);
        if (null === $bytes) {
            return null;
        }

        $name = self::DIRECTORY . '/' . sha1($bytes . $minRatio . $maxRatio) . '.jpg';
        $path = $this->projectDir . '/public/' . $name;
        if (!is_file($path)) {
            if (!is_dir(\dirname($path))) {
                mkdir(\dirname($path), 0o775, true);
            }
            file_put_contents($path, $this->toJpeg($bytes, $minRatio, $maxRatio));
        }

        return $siteUrl . '/' . $name;
    }

    // Draws the picture centred on a canvas stretched to the nearest accepted ratio, then scaled down to MAX_WIDTH
    private function toJpeg(string $bytes, float $minRatio, float $maxRatio): string
    {
        $source = imagecreatefromstring($bytes);
        if (false === $source) {
            throw new \RuntimeException('The image of this post could not be read.');
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $ratio = $width / $height;
        $canvasWidth = $ratio < $minRatio ? (int) round($height * $minRatio) : $width;
        $canvasHeight = $ratio > $maxRatio ? (int) round($width / $maxRatio) : $height;
        $scale = min(1, self::MAX_WIDTH / $canvasWidth);

        $canvas = imagecreatetruecolor(max(1, (int) round($canvasWidth * $scale)), max(1, (int) round($canvasHeight * $scale)));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, ...self::BAND_COLOR));
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

        ob_start();
        imagejpeg($canvas, null, 90);

        return (string) ob_get_clean();
    }
}
