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
use c975L\SocialBundle\Form\Block\SocialLinksType;
use c975L\UiBundle\Form\IconPickerType;
use c975L\UiBundle\Service\IconServiceInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class SocialLinksTypeTest extends TypeTestCase
{
    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own
    // EventDispatcherInterface mock with no configured expectations - forms do dispatch events
    // internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    // "links" is a CollectionType of SocialLinkEntryType, which itself builds on IconPickerType -
    // so it must be resolvable by the form factory exactly as it is in the real app
    protected function getExtensions(): array
    {
        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn(['facebook' => 'icons/facebook.svg']);

        return [new PreloadedExtension([new IconPickerType($iconService)], [])];
    }

    public function testBuildFormAddsIconStyleDisplayLabelAndLinksFields(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $this->assertSame(['iconStyle', 'displayLabel', 'links'], array_keys($form->all()));
    }

    // Matches the ".social-links--{style}" variants styled in sass/_social.scss
    public function testIconStyleFieldOffersMinimalColoredAndOutlineChoices(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $iconStyleField = $form->get('iconStyle');
        $this->assertInstanceOf(ChoiceType::class, $iconStyleField->getConfig()->getType()->getInnerType());
        $this->assertSame(
            ['label.icon_style_minimal' => 'minimal', 'label.icon_style_colored' => 'colored', 'label.icon_style_outline' => 'outline'],
            $iconStyleField->getConfig()->getOption('choices')
        );
    }

    // No empty_data: an unchecked checkbox submits no value at all, indistinguishable from "never
    // touched" - empty_data would force it back to true on every submit, making it impossible to
    // actually uncheck it once saved
    public function testDisplayLabelFieldIsOptionalCheckboxWithoutEmptyData(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $displayLabelField = $form->get('displayLabel');
        $this->assertInstanceOf(CheckboxType::class, $displayLabelField->getConfig()->getType()->getInnerType());
        $this->assertFalse($displayLabelField->getConfig()->getRequired());
    }

    public function testLinksFieldIsAddDeleteCollectionOfSocialLinkEntryType(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $linksField = $form->get('links');
        $this->assertInstanceOf(CollectionType::class, $linksField->getConfig()->getType()->getInnerType());
        $this->assertSame(SocialLinkEntryType::class, $linksField->getConfig()->getOption('entry_type'));
        $this->assertTrue($linksField->getConfig()->getOption('allow_add'));
        $this->assertTrue($linksField->getConfig()->getOption('allow_delete'));
        $this->assertFalse($linksField->getConfig()->getOption('by_reference'));
    }

    public function testSubmitValidDataPopulatesArrayWithLinksEntries(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $form->submit([
            'iconStyle' => 'colored',
            'displayLabel' => '1',
            'links' => [
                ['network' => 'facebook', 'url' => 'https://facebook.com/975l', 'customLabel' => '', 'customIcon' => ''],
            ],
        ]);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('colored', $form->getData()['iconStyle']);
        $this->assertTrue($form->getData()['displayLabel']);
        $this->assertSame('facebook', $form->getData()['links'][0]['network']);
    }

    public function testConfigureOptionsHasNoDataClassAndSocialTranslationDomain(): void
    {
        $form = $this->factory->create(SocialLinksType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
