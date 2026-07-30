<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\ShareButtonsDisplayType;
use c975L\UiBundle\Service\BlockAnchorSlugger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ShareButtonsDisplayTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own EventDispatcherInterface mock with no configured expectations - forms do dispatch events internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // The type now takes a BlockAnchorSlugger, so it can no longer be instantiated by class name alone
    protected function getTypes(): array
    {
        return [new ShareButtonsDisplayType(new BlockAnchorSlugger(new AsciiSlugger()))];
    }

    // Only the anchor: the "share_buttons_display" block kind points at the "share_buttons_settings" singleton (see ShareButtonsSettingsCrudController) for everything it displays, and holds nothing else of its own
    public function testBuildFormAddsOnlyTheAnchorField(): void
    {
        $form = $this->factory->create(ShareButtonsDisplayType::class);

        $this->assertCount(1, $form);
        $this->assertTrue($form->has('anchor'));
        $this->assertFalse($form->get('anchor')->isRequired());
    }

    // This kind carries no title to derive a slug from, so an anchor left empty stays null rather than being invented (same as UiBundle's FeatureBarType) - the band then renders with no id at all
    public function testSubmitLeavesTheAnchorNullWhenNoneIsTyped(): void
    {
        $form = $this->factory->create(ShareButtonsDisplayType::class);

        $form->submit(['anchor' => '']);

        $this->assertTrue($form->isSynchronized());
        $this->assertNull($form->getData()['anchor']);
    }

    // A typed anchor is slugified, so it is always a valid HTML id whatever the editor types
    public function testSubmitSlugifiesTheTypedAnchor(): void
    {
        $form = $this->factory->create(ShareButtonsDisplayType::class);

        $form->submit(['anchor' => 'Partage le ras-le-bol']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('partage-le-ras-le-bol', $form->getData()['anchor']);
    }

    public function testConfigureOptionsUsesSocialTranslationDomainAndNoDataClass(): void
    {
        $form = $this->factory->create(ShareButtonsDisplayType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
