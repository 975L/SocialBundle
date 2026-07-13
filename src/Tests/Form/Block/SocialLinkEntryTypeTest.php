<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\SocialLinkEntryType;
use c975L\UiBundle\Form\IconPickerType;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class SocialLinkEntryTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own
    // EventDispatcherInterface mock with no configured expectations - forms do dispatch events
    // internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // "network"/"customIcon" both build on UiBundle's IconPickerType, so it must be resolvable
    // by the form factory exactly as it is in the real app (an autowired service)
    protected function getExtensions(): array
    {
        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn(['facebook' => 'icons/facebook.svg', 'alert' => 'icons/alert.svg']);

        return [new PreloadedExtension([new IconPickerType($iconService)], [])];
    }

    public function testBuildFormAddsNetworkUrlCustomLabelAndCustomIconFields(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $this->assertSame(['network', 'url', 'customLabel', 'customIcon'], array_keys($form->all()));
    }

    // "network" is restricted to the curated NETWORKS list and stores the bare key ("facebook"),
    // not an icon path - see SocialLinkExtension::getSocialLinkIcon(), which resolves that key
    // to an icon path at render time
    public function testNetworkFieldIsRestrictedIconPickerStoringNetworkName(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $networkField = $form->get('network');
        $this->assertInstanceOf(IconPickerType::class, $networkField->getConfig()->getType()->getInnerType());
        $this->assertFalse($networkField->getConfig()->getRequired());
        $this->assertSame('name', $networkField->getConfig()->getOption('value_field'));
        $this->assertContains('facebook', $networkField->getConfig()->getOption('icons'));
        $this->assertNotContains('alert', $networkField->getConfig()->getOption('icons'));
    }

    // "customIcon" is the escape hatch for a network with no curated icon - unlike "network"
    // above, it isn't restricted to NETWORKS and stores the icon's asset path (IconPickerType's
    // own default), not a bare key
    public function testCustomIconFieldIsUnrestrictedIconPickerStoringPath(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $customIconField = $form->get('customIcon');
        $this->assertInstanceOf(IconPickerType::class, $customIconField->getConfig()->getType()->getInnerType());
        $this->assertFalse($customIconField->getConfig()->getRequired());
        $this->assertNull($customIconField->getConfig()->getOption('icons'));
        $this->assertSame('path', $customIconField->getConfig()->getOption('value_field'));
    }

    public function testUrlFieldIsRequiredUrlType(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $urlField = $form->get('url');
        $this->assertInstanceOf(UrlType::class, $urlField->getConfig()->getType()->getInnerType());
        $this->assertTrue($urlField->getConfig()->getRequired());
    }

    // Only used when "network" is left empty - see social_link_entry_form_theme.html.twig
    public function testCustomLabelFieldIsOptionalTextType(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $customLabelField = $form->get('customLabel');
        $this->assertInstanceOf(TextType::class, $customLabelField->getConfig()->getType()->getInnerType());
        $this->assertFalse($customLabelField->getConfig()->getRequired());
    }

    public function testSubmitValidDataPopulatesArray(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $form->submit([
            'network' => 'facebook',
            'url' => 'https://facebook.com/975l',
            'customLabel' => '',
            'customIcon' => '',
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(
            ['network' => 'facebook', 'url' => 'https://facebook.com/975l', 'customLabel' => null, 'customIcon' => null],
            $form->getData()
        );
    }

    public function testConfigureOptionsHasNoDataClassNoLabelAndSocialTranslationDomain(): void
    {
        $form = $this->factory->create(SocialLinkEntryType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertFalse($form->getConfig()->getOption('label'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
