<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Twig;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\GoogleBusinessProfileSource;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Twig\Attribute\AsTwigFunction;

class SocialLinkExtension
{
    // The tag of the cached singleton and of every render of it, dropped when the block is saved (SingletonBlockCacheInvalidationListener) or the Google listing url changes (GoogleReviewsCacheInvalidationListener)
    public const string CACHE_TAG = 'singleton_block_' . self::KIND;

    // Kind of the "social_links" singleton Block (see SocialLinksCrudController)
    private const string KIND = 'social_links';

    // The networks whose name is not their key capitalized - every other one reads as "Facebook" from "facebook"
    private const array LABELS = [
        'applepodcasts' => 'Apple Podcasts',
        'github' => 'GitHub',
        'linkedin' => 'LinkedIn',
        'soundcloud' => 'SoundCloud',
        'tiktok' => 'TikTok',
        'vk' => 'VK',
        'wechat' => 'WeChat',
        'whatsapp' => 'WhatsApp',
        'wordpress' => 'WordPress',
        'youtube' => 'YouTube',
    ];

    public function __construct(
        private readonly BlockRepository $blockRepository,
        private readonly IconServiceInterface $iconService,
        private readonly TagAwareCacheInterface $cache,
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    // Cross-request cache: this singleton Block is read on every page rendering the site-wide footer links, and barely ever changes - invalidated by SingletonBlockCacheInvalidationListener whenever it's saved/removed. Safe to cache the entity directly: SocialLinks.html.twig (the "social_links" kind's own template, reached through render_block()) only ever reads block.data, never block.media/block.user
    #[AsTwigFunction('social_link_block')]
    public function getSocialLinkBlock(): ?Block
    {
        return $this->cache->get(self::CACHE_TAG, function (ItemInterface $item): ?Block {
            $item->expiresAfter(null);
            $item->tag([self::CACHE_TAG]);

            return $this->blockRepository->findOneByKind(self::KIND);
        });
    }

    // Resolves a SocialLinkEntryType "network" key to its icon path - always the same flat Font Awesome glyph regardless of the block's "iconStyle" (see SocialLinksType), which only recolors it via CSS (sass/_social.scss), not a separate icon asset
    #[AsTwigFunction('social_link_icon')]
    public function getSocialLinkIcon(string $network): ?string
    {
        return $this->iconService->getIcons()[$network] ?? null;
    }

    // The network's name as its brand writes it, for the link's label and aria-label
    #[AsTwigFunction('social_link_label')]
    public function getSocialLinkLabel(string $network): string
    {
        return self::LABELS[$network] ?? ucfirst($network);
    }

    // The block's links followed by the Google listing, read from "social-google-listing-url" rather than retyped - left out while the key is empty or when a link already points to it
    /**
     * @param array<mixed> $links
     *
     * @return array<mixed>
     */
    #[AsTwigFunction('social_links_with_listing')]
    public function getLinksWithListing(array $links): array
    {
        $listing = trim((string) $this->configService->get(GoogleBusinessProfileSource::LISTING_URL));
        if ('' === $listing || \in_array($listing, array_column($links, 'url'), true)) {
            return $links;
        }

        return [...$links, ['network' => 'google', 'url' => $listing]];
    }
}
