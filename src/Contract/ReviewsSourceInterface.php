<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Contract;

use c975L\SocialBundle\Model\ReviewData;

// Implement to feed reviews from a platform (Google Business Profile, and whatever a site adds next) into the shared Review entity - auto-discovered by interface, no tag needed, see c975LSocialBundle::build()
interface ReviewsSourceInterface
{
    // The name rows imported here are stored under, in Review::source - a sync only ever touches its own
    public function getName(): string;

    // Whether the source holds the credentials it needs, so a sync skips it rather than failing on a site that never configured it
    public function isConfigured(): bool;

    // The reviews the source currently exposes, upserted on getName() plus ReviewData::externalId
    /**
     * @return iterable<ReviewData>
     */
    public function fetch(): iterable;
}
