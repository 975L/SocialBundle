<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\SocialLinksDisplayType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Test\TypeTestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class SocialLinksDisplayTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own
    // EventDispatcherInterface mock with no configured expectations - forms do dispatch events
    // internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // No fields: the "social_links_display" block kind only points at the "social_links" singleton
    // (see SocialLinksCrudController), it holds no data of its own
    public function testBuildFormAddsNoChildren(): void
    {
        $form = $this->factory->create(SocialLinksDisplayType::class);

        $this->assertCount(0, $form);
    }

    public function testConfigureOptionsUsesSocialTranslationDomainAndNoDataClass(): void
    {
        $form = $this->factory->create(SocialLinksDisplayType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
