<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\SocialLinksPreviewType;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Test\TypeTestCase;

class SocialLinksPreviewTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own
    // EventDispatcherInterface mock with no configured expectations - forms do dispatch events
    // internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // Dedicated block prefix so social_links_preview_theme.html.twig can override just this
    // field's widget - see the class-level comment for why (EasyAdmin only honors
    // setTemplatePath() on index/detail, never on New/Edit)
    public function testGetParentAndBlockPrefixEnableTheDedicatedFormTheme(): void
    {
        $type = new SocialLinksPreviewType();

        $this->assertSame(TextType::class, $type->getParent());
        $this->assertSame('social_links_preview', $type->getBlockPrefix());
    }

    public function testConfigureOptionsDefaultsToEmptyLinksVisibleLabelAndMinimalStyle(): void
    {
        $form = $this->factory->create(SocialLinksPreviewType::class);

        $this->assertSame([], $form->getConfig()->getOption('links'));
        $this->assertTrue($form->getConfig()->getOption('display_label'));
        $this->assertSame('minimal', $form->getConfig()->getOption('icon_style'));
    }

    // buildView() copies the options straight onto the view - the theme template only ever
    // reads $links/$display_label/$icon_style off the view, never the (unmapped) form data
    public function testBuildViewExposesLinksDisplayLabelAndIconStyleAsViewVars(): void
    {
        $links = [['network' => 'facebook', 'url' => 'https://facebook.com/975l', 'customLabel' => null, 'customIcon' => null]];

        $form = $this->factory->create(SocialLinksPreviewType::class, null, [
            'links' => $links,
            'display_label' => false,
            'icon_style' => 'colored',
        ]);

        $view = $form->createView();

        $this->assertSame($links, $view->vars['links']);
        $this->assertFalse($view->vars['display_label']);
        $this->assertSame('colored', $view->vars['icon_style']);
    }
}
