<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

use c975L\SocialBundle\Entity\SocialPost;
use Symfony\Component\Translation\TranslatableMessage;

// A network asking, post by post, choices the text does not carry (TikTok's privacy, interactions, disclosure and consent): a form of its own on the post's screen, kept on the target, checked before the post is approved, then handed to the publication. Implemented beside NetworkPublisherInterface, the publication checking for it
interface PostOptionsPublisherInterface
{
    // The form type of the choices, mapped to an array and built on the post's screen with whatever the network says live
    public function getOptionsType(): string;

    // What keeps the post from being approved with these choices, empty when nothing does - $live asking the network too, at the approval only
    /**
     * @param array<string, mixed> $options
     *
     * @return list<TranslatableMessage>
     */
    public function checkOptions(array $options, SocialPost $post, bool $live = false): array;

    // The same publisher, publishing and previewing with these choices
    /** @param array<string, mixed> $options */
    public function withOptions(array $options): NetworkPublisherInterface;
}
