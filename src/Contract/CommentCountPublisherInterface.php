<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

use c975L\SocialBundle\Model\CommentCount;
use c975L\SocialBundle\Model\PostComment;

// A network saying how many comments a post holds and what the latest say, what the alert of new comments reads (see SocialCommentChecker). Implemented beside NetworkPublisherInterface, the check looking for it
interface CommentCountPublisherInterface
{
    // The comments of the post $externalId and its public address. Throws rather than reporting failure, the post being read again at the next check
    public function commentCount(string $externalId): CommentCount;

    // The $limit newest comments of the post $externalId, asked only when some are new. Throws when the network refuses, a permission the connection was not granted included
    /** @return list<PostComment> */
    public function latestComments(string $externalId, int $limit): array;
}
