<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

// Implemented on top of ReviewsSourceInterface by the platforms whose reviews can be answered - kept apart so a read-only source has no method to stub, and so the admin screen can hide the reply field for the sources that have none
interface ReviewsReplySourceInterface extends ReviewsSourceInterface
{
    // Publishes the owner's answer on the platform; an empty reply removes it, the site never keeping an answer its author withdrew
    public function reply(string $externalId, ?string $comment): void;
}
