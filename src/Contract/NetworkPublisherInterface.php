<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

use c975L\UiBundle\Model\SocialContent;

// One network the scheduled publication posts to - auto-tagged by interface (see c975LSocialBundle::build()), so a site adding a network of its own only implements it. Each network reads its own configs, the slugs staying its business
interface NetworkPublisherInterface
{
    // The name stored with each target ("bluesky")
    public function getName(): string;

    // Whether the site holds the credentials this network needs - an unconfigured one gets no target at all
    public function isConfigured(): bool;

    // Whether a prepared post goes out on its own, or waits as a draft for someone to read it and press "Publish"
    public function isAutomatic(): bool;

    // The longest text this network accepts, in characters - what the text is cut to when prepared, and what the screen tells whoever edits it
    public function getMaxLength(): int;

    // Posts the text with the content's image and returns the network's id for it. Throws rather than reporting failure, so the target is marked failed with the network's own reason
    public function publish(string $text, SocialContent $content): string;

    // What publish() would send, sent nowhere - the dry run's whole point being to read the exact payload before any credential is plugged in
    /** @return array<string, mixed> */
    public function preview(string $text, SocialContent $content): array;
}
