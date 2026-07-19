<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Twig;

use c975L\SocialBundle\Twig\SocialLinkExtension;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use c975L\UiBundle\Service\IconServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Contracts\Cache\TagAwareCacheInterface;
use Twig\TwigFunction;

class SocialLinkExtensionTest extends TestCase
{
    // Real in-memory tag-aware pool (not a stub): storeSerialized stays at its default (true), same as the production filesystem-backed pool, so a test would catch a Block that doesn't actually survive a cache round-trip
    private function createCache(): TagAwareCacheInterface
    {
        return new TagAwareAdapter(new ArrayAdapter());
    }

    // Builds the extension, with a BlockRepository double answering "social_links" with $socialLinksBlock
    private function createExtension(
        ?Block $socialLinksBlock,
        array $icons = [],
        ?TagAwareCacheInterface $cache = null,
    ): SocialLinkExtension {
        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturnCallback(
            static fn (string $kind) => 'social_links' === $kind ? $socialLinksBlock : null
        );

        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn($icons);

        return new SocialLinkExtension($blockRepository, $iconService, $cache ?? $this->createCache());
    }

    // Both functions are plain value-returning helpers, not html-rendering ones (no needs_environment/is_safe)
    public function testGetFunctionsRegistersSocialLinkBlockAndSocialLinkIcon(): void
    {
        $extension = $this->createExtension(null);

        $functions = $extension->getFunctions();

        $this->assertCount(2, $functions);
        $this->assertSame(
            ['social_link_block', 'social_link_icon'],
            array_map(static fn (TwigFunction $function) => $function->getName(), $functions)
        );
        foreach ($functions as $function) {
            $this->assertFalse($function->needsEnvironment());
        }
    }

    // The layout renders the "social_links" singleton block whenever it has been created
    public function testGetSocialLinkBlockReturnsBlockPersistedUnderSocialLinksKind(): void
    {
        $block = (new Block())->setKind('social_links')->setData(['links' => []]);
        $extension = $this->createExtension($block);

        // Not assertSame(): the cached-and-reconstructed Block is a fresh object after the pool's (de)serialization round-trip, equal in value but no longer the same instance
        $this->assertEquals($block, $extension->getSocialLinkBlock());
    }

    // Before the block has ever been created, the layout must be able to skip rendering it entirely
    public function testGetSocialLinkBlockReturnsNullWhenNoBlockOfThatKindExists(): void
    {
        $extension = $this->createExtension(null);

        $this->assertNull($extension->getSocialLinkBlock());
    }

    // A base layout typically calls social_link_block() once per render, but a page could embed it more than once (e.g. header and footer) - only the first call in a request should hit the repository
    public function testGetSocialLinkBlockMemoizesWithinTheSameCacheInstance(): void
    {
        $block = (new Block())->setKind('social_links')->setData(['links' => []]);
        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('social_links')->willReturn($block);

        $iconService = $this->createStub(IconServiceInterface::class);
        $cache = $this->createCache();
        $extension = new SocialLinkExtension($blockRepository, $iconService, $cache);

        $extension->getSocialLinkBlock();
        $extension->getSocialLinkBlock();
    }

    // The whole point of a cross-request cache: a fresh SocialLinkExtension instance (simulating a new request) sharing the same cache pool must not hit the repository again
    public function testGetSocialLinkBlockSurvivesAcrossInstancesSharingTheSameCachePool(): void
    {
        $block = (new Block())->setKind('social_links')->setData(['links' => []]);
        $blockRepository = $this->createMock(BlockRepository::class);
        $blockRepository->expects($this->once())->method('findOneByKind')->with('social_links')->willReturn($block);
        $iconService = $this->createStub(IconServiceInterface::class);

        $cache = $this->createCache();
        $firstRequest = new SocialLinkExtension($blockRepository, $iconService, $cache);
        $this->assertEquals($block, $firstRequest->getSocialLinkBlock());

        $secondRequest = new SocialLinkExtension($blockRepository, $iconService, $cache);
        $this->assertEquals($block, $secondRequest->getSocialLinkBlock());
    }

    // A configured network resolves to the same flat Font Awesome glyph IconService exposes for it
    public function testGetSocialLinkIconReturnsPathForKnownNetwork(): void
    {
        $extension = $this->createExtension(null, ['facebook' => 'icons/facebook.svg']);

        $this->assertSame('icons/facebook.svg', $extension->getSocialLinkIcon('facebook'));
    }

    // An unrecognized network key must not error out, just render without an icon
    public function testGetSocialLinkIconReturnsNullForUnknownNetwork(): void
    {
        $extension = $this->createExtension(null, ['facebook' => 'icons/facebook.svg']);

        $this->assertNull($extension->getSocialLinkIcon('myspace'));
    }
}
