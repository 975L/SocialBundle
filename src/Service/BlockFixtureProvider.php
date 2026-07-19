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

// None of SocialBundle's block kinds needs a fixture here: "social_links" itself is "pickable: false" (a singleton managed through its own dedicated admin entry - see BlockRegistry::groupedByCategory()), so it never appears in the gallery at all; "social_links_display" and "share_buttons_display" are covered instead by GalleryShowcaseProvider (their own data never drives their render anyway - see SocialLinksDisplay.html.twig/ShareButtonsDisplay.html.twig - so a plain fixture here would only ever duplicate that showcase).
class BlockFixtureProvider implements BlockFixtureProviderInterface
{
    public function getFixtures(): array
    {
        return [];
    }
}
