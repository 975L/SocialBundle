<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

// A network posting as a Page that shares the post again on the profile of the member who connected it, so the post reaches the member's own network too. Implemented beside NetworkPublisherInterface, the publication checking for it
interface ProfileResharePublisherInterface
{
    // Whether the posts go out as a Page, a profile having nothing to share again on itself
    public function canReshare(): bool;

    // Shares the post $postId again on the member's profile with $text above it, returning the reshare's id. Throws rather than reporting failure, the post itself staying published
    public function reshare(string $postId, string $text): string;
}
