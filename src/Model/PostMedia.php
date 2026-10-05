<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Model;

// A picture or a video of a post as a network receives it: the file on disk, its public url, and what was measured of it (see SocialMedia)
final class PostMedia
{
    public function __construct(
        public readonly string $path,
        public readonly string $url,
        public readonly string $mimeType,
        public readonly ?int $size = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly ?float $duration = null,
        public readonly ?string $alt = null,
    ) {
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->mimeType, 'video/');
    }

    // The width over the height, null while either is unknown
    public function ratio(): ?float
    {
        return null === $this->width || null === $this->height || 0 === $this->height ? null : $this->width / $this->height;
    }
}
