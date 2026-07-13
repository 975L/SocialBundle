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
use Twig\TwigFunction;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class SocialLinkExtensionTest extends TestCase
{
    // Builds the extension, with a BlockRepository double answering "social_links" with $socialLinksBlock
    private function createExtension(?Block $socialLinksBlock, array $icons = []): SocialLinkExtension
    {
        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturnCallback(
            static fn (string $kind) => 'social_links' === $kind ? $socialLinksBlock : null
        );

        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn($icons);

        return new SocialLinkExtension($blockRepository, $iconService);
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
        $block = new Block();
        $extension = $this->createExtension($block);

        $this->assertSame($block, $extension->getSocialLinkBlock());
    }

    // Before the block has ever been created, the layout must be able to skip rendering it entirely
    public function testGetSocialLinkBlockReturnsNullWhenNoBlockOfThatKindExists(): void
    {
        $extension = $this->createExtension(null);

        $this->assertNull($extension->getSocialLinkBlock());
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
