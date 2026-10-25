<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

// A network that answers its own post with a first comment, where the link goes when the post leaves it out - LinkedIn showing a post carrying a link to fewer people. Implemented beside NetworkPublisherInterface, the publication checking for it
interface FirstCommentPublisherInterface
{
    // Comments the post $postId as the account that posted it, returning the comment's id. Throws rather than reporting failure, the post itself staying published
    public function comment(string $postId, string $text): string;
}
