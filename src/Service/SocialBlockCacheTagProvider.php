<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Service;

use c975L\UiBundle\Contract\BlockCacheTagProviderInterface;
use c975L\UiBundle\Entity\Block;

// "social_links_display" holds no data of its own and always renders the site-wide singleton (see SocialLinksDisplay.html.twig), which BlockCacheInvalidationListener has no way to tie it to: the tag it drops names the edited Block, and the one edited is the singleton. So the pointer's entry carries the singleton's own tag, the very one SingletonBlockCacheInvalidationListener already drops for SocialLinkExtension's cached entity - nothing new to invalidate, the pointer simply joins what was already being dropped
class SocialBlockCacheTagProvider implements BlockCacheTagProviderInterface
{
    // Written as SocialLinkExtension writes it, for the same reason it is not shared as a constant there: the two are the only readers of that key
    private const string SINGLETON_TAG = 'singleton_block_social_links';

    public function getCacheTagResolvers(): array
    {
        return [
            'social_links_display' => static fn (Block $block): array => [self::SINGLETON_TAG],
        ];
    }
}
