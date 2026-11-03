<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

// A network sharing again, as it stands, a post another account published - no text of its own, and nothing else sent. Implemented beside NetworkPublisherInterface, the publication checking for it
interface RepostPublisherInterface
{
    // Whether the account connected was granted what reposting takes
    public function canRepost(): bool;

    // Reposts the latest post of $account carrying the link $url, returning the repost's id. Throws when the network refuses or the post is not out yet
    public function repost(string $account, string $url): string;
}
