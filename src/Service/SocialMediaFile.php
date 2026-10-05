<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use Imagine\Filter\Basic\Autorotate;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Palette\RGB;
use Imagine\Image\Point;

// A media file made into what every network takes, and measured: a picture rewritten as a downscaled JPEG, a video read by ffprobe where the host has it - what an upload (see SocialMediaUploadListener) and a series' media (see SocialSeriesGenerator) both go through
class SocialMediaFile
{
    // The longest side kept, above what any network shows
    public const int MAX_SIDE = 2048;

    private const int JPEG_QUALITY = 85;

    // Width, height and, for a video, duration - each null where it cannot be read
    /** @return array{0: ?int, 1: ?int, 2: ?float} */
    public function measure(string $path, bool $video): array
    {
        if ($video) {
            return $this->probe($path);
        }

        $size = @getimagesize($path);

        return false === $size ? [null, null, null] : [$size[0], $size[1], null];
    }

    // Upright as the camera saw it, no larger than MAX_SIDE, its transparency laid on white rather than the black a JPEG would give it
    public function toJpeg(string $path): void
    {
        $imagine = new Imagine();
        $image = $imagine->open($path);
        new Autorotate()->apply($image);

        $size = $image->getSize();
        if (max($size->getWidth(), $size->getHeight()) > self::MAX_SIDE) {
            $image = $image->thumbnail(new Box(self::MAX_SIDE, self::MAX_SIDE), ImageInterface::THUMBNAIL_INSET);
            $size = $image->getSize();
        }

        $canvas = $imagine->create($size, new RGB()->color('#ffffff'));
        $canvas->paste($image, new Point(0, 0));
        $canvas->save($path, ['format' => 'jpeg', 'jpeg_quality' => self::JPEG_QUALITY]);
        clearstatcache(true, $path);
    }

    // Width, height and duration off ffprobe - all three null on a host without it, the rules then stepping over what they cannot read
    /** @return array{0: ?int, 1: ?int, 2: ?float} */
    private function probe(string $path): array
    {
        if (!\function_exists('exec')) {
            return [null, null, null];
        }

        $output = [];
        @exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height:format=duration -of json ' . escapeshellarg($path) . ' 2>/dev/null', $output, $code);
        $data = 0 === $code ? json_decode(implode('', $output), true) : null;
        if (!\is_array($data)) {
            return [null, null, null];
        }

        $stream = $data['streams'][0] ?? [];
        $duration = $data['format']['duration'] ?? null;

        return [isset($stream['width']) ? (int) $stream['width'] : null, isset($stream['height']) ? (int) $stream['height'] : null, is_numeric($duration) ? (float) $duration : null];
    }
}
