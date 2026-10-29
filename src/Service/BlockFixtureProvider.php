<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\UiBundle\Contract\BlockFixtureProviderInterface;

// None of SocialBundle's block kinds needs a fixture here: "social_links" itself is "pickable: false" (a singleton managed through its own dedicated admin entry - see BlockRegistry::groupedByCategory()), so it never appears in the gallery at all; "social_links_display", "share_buttons_display" and "google_reviews" are covered instead by GalleryShowcaseProvider (their own data never drives what they show anyway - the singletons for the first two, the moderated reviews for the third - so a plain fixture here would only ever duplicate that showcase, or render empty).
class BlockFixtureProvider implements BlockFixtureProviderInterface
{
    public function getFixtures(): array
    {
        return [];
    }
}
