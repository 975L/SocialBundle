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
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SocialLinkExtension extends AbstractExtension
{
    public function __construct(
        private readonly BlockRepository $blockRepository,
        private readonly IconServiceInterface $iconService,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('social_link_block', [$this, 'getSocialLinkBlock']),
            new TwigFunction('social_link_icon', [$this, 'getSocialLinkIcon']),
        ];
    }

    public function getSocialLinkBlock(): ?Block
    {
        return $this->blockRepository->findOneByKind('social_links');
    }

    // Resolves a SocialLinkEntryType "network" key to its icon path - always the same flat Font Awesome
    // glyph regardless of the block's "iconStyle" (see SocialLinksType), which only recolors it via CSS
    // (sass/_social.scss), not a separate icon asset
    public function getSocialLinkIcon(string $network): ?string
    {
        return $this->iconService->getIcons()[$network] ?? null;
    }
}
