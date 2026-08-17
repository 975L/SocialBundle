<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Service\SameAsProvider;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use PHPUnit\Framework\TestCase;

class SameAsProviderTest extends TestCase
{
    private function createProvider(?array $links, ?string $listingUrl): SameAsProvider
    {
        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturn(
            null === $links ? null : new Block()->setKind('social_links')->setData(['links' => $links])
        );

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($listingUrl);

        return new SameAsProvider($blockRepository, $configService);
    }

    // The listing comes first: it is the profile Google reconciles the site against, a social account only corroborating it
    public function testTheListingIsPublishedBeforeTheSocialLinks(): void
    {
        $provider = $this->createProvider(
            [['network' => 'facebook', 'url' => 'https://facebook.com/autotech']],
            'https://www.google.com/maps?cid=1'
        );

        $this->assertSame(
            ['https://www.google.com/maps?cid=1', 'https://facebook.com/autotech'],
            $provider->getSameAs()
        );
    }

    // A site that never filled the block, or never saved a link, must contribute nothing rather than an empty entry
    public function testAMissingBlockContributesNothing(): void
    {
        $this->assertSame([], $this->createProvider(null, null)->getSameAs());
    }

    public function testALinkWithNoUrlIsSkipped(): void
    {
        $provider = $this->createProvider(
            [['network' => 'facebook', 'url' => '  '], ['network' => 'x', 'url' => 'https://x.com/autotech']],
            null
        );

        $this->assertSame(['https://x.com/autotech'], $provider->getSameAs());
    }

    // A way to reach the business is not a page naming it, which is all "sameAs" is read as
    public function testANonHttpSchemeIsSkipped(): void
    {
        $provider = $this->createProvider(
            [
                ['network' => 'email', 'url' => 'mailto:contact@autotech.fr'],
                ['network' => 'phone', 'url' => 'tel:+33450000000'],
                ['network' => 'facebook', 'url' => 'https://facebook.com/autotech'],
            ],
            null
        );

        $this->assertSame(['https://facebook.com/autotech'], $provider->getSameAs());
    }
}
