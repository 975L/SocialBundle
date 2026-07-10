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
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class SocialLinkExtension extends AbstractExtension
{
    public function __construct(private readonly BlockRepository $blockRepository)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('social_link_block', [$this, 'getSocialLinkBlock']),
        ];
    }

    public function getSocialLinkBlock(): ?Block
    {
        return $this->blockRepository->findOneByKind('social_links');
    }
}
