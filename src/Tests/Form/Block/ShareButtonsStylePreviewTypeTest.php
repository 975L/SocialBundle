<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\ShareButtonsStylePreviewType;
use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class ShareButtonsStylePreviewTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own
    // EventDispatcherInterface mock with no configured expectations - forms do dispatch events
    // internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    private function createShareButtonsService(array $networks = ['facebook', 'bluesky']): ShareButtonsServiceInterface
    {
        $shareButtonsService = $this->createStub(ShareButtonsServiceInterface::class);
        $shareButtonsService->method('getNetworks')->willReturn($networks);

        return $shareButtonsService;
    }

    protected function getExtensions(): array
    {
        return [new PreloadedExtension([new ShareButtonsStylePreviewType($this->createShareButtonsService())], [])];
    }

    // Dedicated block prefix so share_buttons_style_preview_theme.html.twig can override just
    // this field's widget - see the class-level comment for why (EasyAdmin only honors
    // setTemplatePath() on index/detail, never on New/Edit)
    public function testGetParentAndBlockPrefixEnableTheDedicatedFormTheme(): void
    {
        $type = new ShareButtonsStylePreviewType($this->createShareButtonsService());

        $this->assertSame(TextType::class, $type->getParent());
        $this->assertSame('share_buttons_style_preview', $type->getBlockPrefix());
    }

    // Every known network is rendered into the preview (client-side JS hides/reorders them to
    // match the "networks" checkboxes live), not just whichever are actually checked
    public function testBuildViewExposesAllNetworksRegardlessOfSavedSelection(): void
    {
        $form = $this->factory->create(ShareButtonsStylePreviewType::class);

        $view = $form->createView();

        $this->assertSame(['facebook', 'bluesky'], $view->vars['all_networks']);
    }
}
