<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Model;

// How many comments a post holds on a network, and where to read them
final readonly class CommentCount
{
    public function __construct(
        public int $count,
        public ?string $url = null,
    ) {
    }
}
