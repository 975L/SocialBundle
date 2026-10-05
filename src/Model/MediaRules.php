<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Model;

// What a network takes with a post, from its own documentation (see each publisher's getMediaRules()): a null limit is one the network does not document, never checked
final class MediaRules
{
    /**
     * @param int          $maxImages  the pictures one post carries at most, 0 for none
     * @param bool         $video      whether a post may carry a video, one at most
     * @param bool         $mix        whether pictures and a video go together in one post
     * @param bool         $required   whether a post goes out with no media at all
     * @param list<string> $imageTypes the picture types it takes, as uploaded or as sent
     * @param list<string> $videoTypes the video types it takes
     */
    public function __construct(
        public readonly int $maxImages,
        public readonly bool $video,
        public readonly bool $mix = false,
        public readonly bool $required = false,
        public readonly array $imageTypes = ['image/jpeg'],
        public readonly ?int $maxImageBytes = null,
        public readonly ?float $minRatio = null,
        public readonly ?float $maxRatio = null,
        public readonly array $videoTypes = ['video/mp4'],
        public readonly ?int $maxVideoBytes = null,
        public readonly ?float $minDuration = null,
        public readonly ?float $maxDuration = null,
    ) {
    }
}
