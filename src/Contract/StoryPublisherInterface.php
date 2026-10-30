<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

use c975L\SocialBundle\Model\PostMedia;
use c975L\UiBundle\Model\SocialContent;

// A network that shows the post's video or picture as a story too, for 24 hours, right after the post. A story created through the API takes no link nor sticker, so it never leads to the post: it is its own story. Implemented beside NetworkPublisherInterface, the publication checking for it
interface StoryPublisherInterface
{
    // Publishes $video as a story when the post has one, $content's image framed vertically otherwise, returning the story's id. Throws rather than reporting failure, the post itself staying published
    public function story(SocialContent $content, ?PostMedia $video = null): string;
}
