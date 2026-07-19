<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Twig;

use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SocialLinkExtension extends AbstractExtension
{
    // Kind of the "social_links" singleton Block (see SocialLinksCrudController) - not shared as a public constant there either, matching ShareButtonsExtension's own literal use of "share_buttons_settings" for the same reason (no other consumer needs it)
    private const KIND = 'social_links';

    public function __construct(
        private readonly BlockRepository $blockRepository,
        private readonly IconServiceInterface $iconService,
        private readonly TagAwareCacheInterface $cache,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('social_link_block', [$this, 'getSocialLinkBlock']),
            new TwigFunction('social_link_icon', [$this, 'getSocialLinkIcon']),
        ];
    }

    // Cross-request cache: this singleton Block is read on every page rendering the site-wide footer links, and barely ever changes - invalidated by SingletonBlockCacheInvalidationListener whenever it's saved/removed. Safe to cache the entity directly: SocialLinks.html.twig (the "social_links" kind's own template, reached through render_block()) only ever reads block.data, never block.media/block.user
    public function getSocialLinkBlock(): ?Block
    {
        return $this->cache->get('singleton_block_' . self::KIND, function (ItemInterface $item): ?Block {
            $item->expiresAfter(null);
            $item->tag(['singleton_block_' . self::KIND]);

            return $this->blockRepository->findOneByKind(self::KIND);
        });
    }

    // Resolves a SocialLinkEntryType "network" key to its icon path - always the same flat Font Awesome glyph regardless of the block's "iconStyle" (see SocialLinksType), which only recolors it via CSS (sass/_social.scss), not a separate icon asset
    public function getSocialLinkIcon(string $network): ?string
    {
        return $this->iconService->getIcons()[$network] ?? null;
    }
}
