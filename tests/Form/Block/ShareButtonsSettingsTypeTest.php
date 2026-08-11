<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Form\Block;

use c975L\SocialBundle\Form\Block\ShareButtonsSettingsType;
use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use c975L\UiBundle\Service\BlockAnchorSlugger;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

class ShareButtonsSettingsTypeTest extends TypeTestCase
{
    private const array NETWORKS = ['facebook', 'bluesky', 'linkedin', 'pinterest', 'email'];
    private const array SHAPES = ['wide', 'ellipse', 'circle'];
    private const array FILLS = ['solid', 'transparent', 'outline'];

    // Pre-seeds a stub before TypeTestCase::setUp() runs, since it otherwise creates its own EventDispatcherInterface mock with no configured expectations - forms do dispatch events internally (PRE_SET_DATA...), which PHPUnit 13 now flags as "mock used without expectations"
    protected function setUp(): void
    {
        $this->dispatcher = $this->createStub(EventDispatcherInterface::class);
        parent::setUp();
    }

    private function createShareButtonsService(): ShareButtonsServiceInterface
    {
        $shareButtonsService = $this->createStub(ShareButtonsServiceInterface::class);
        $shareButtonsService->method('getNetworks')->willReturn(self::NETWORKS);
        $shareButtonsService->method('getShapes')->willReturn(self::SHAPES);
        $shareButtonsService->method('getFills')->willReturn(self::FILLS);

        return $shareButtonsService;
    }

    #[\Override]
    protected function getExtensions(): array
    {
        return [new PreloadedExtension([new ShareButtonsSettingsType($this->createShareButtonsService(), new BlockAnchorSlugger(new AsciiSlugger()))], [])];
    }

    // Both fields are (re)built from scratch on PRE_SET_DATA (see the class-level comment for why), which the form factory always triggers once on creation - so they must already be present even before any explicit submit/setData call
    public function testBuildFormAddsNetworksAndStyleFieldsOnCreation(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $this->assertSame(['networks', 'shape', 'fill', 'displayIntro', 'anchor'], array_keys($form->all()));
    }

    public function testNetworksFieldIsMultipleExpandedChoiceOfAllNetworks(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $networksField = $form->get('networks');
        $this->assertInstanceOf(ChoiceType::class, $networksField->getConfig()->getType()->getInnerType());
        $this->assertTrue($networksField->getConfig()->getOption('multiple'));
        $this->assertTrue($networksField->getConfig()->getOption('expanded'));
        $this->assertFalse($networksField->getConfig()->getRequired());
    }

    public function testShapeFieldIsSingleChoiceOfAllShapesWithTranslatedLabels(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $shapeField = $form->get('shape');
        $this->assertInstanceOf(ChoiceType::class, $shapeField->getConfig()->getType()->getInnerType());
        $this->assertFalse($shapeField->getConfig()->getOption('expanded'));
        $this->assertSame(
            ['label.shape_wide' => 'wide', 'label.shape_ellipse' => 'ellipse', 'label.shape_circle' => 'circle'],
            $shapeField->getConfig()->getOption('choices')
        );
    }

    public function testFillFieldIsSingleChoiceOfAllFillsWithTranslatedLabels(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $fillField = $form->get('fill');
        $this->assertInstanceOf(ChoiceType::class, $fillField->getConfig()->getType()->getInnerType());
        $this->assertFalse($fillField->getConfig()->getOption('expanded'));
        $this->assertSame(
            ['label.fill_solid' => 'solid', 'label.fill_transparent' => 'transparent', 'label.fill_outline' => 'outline'],
            $fillField->getConfig()->getOption('choices')
        );
    }

    // A singleton saved before shape and fill became two settings carries neither: both selects must open on the defaults, its single "style" not being read at all anymore
    public function testBothSelectsOpenOnTheDefaultsForASingletonCarryingOnlyTheOldStyleKey(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['facebook'], 'style' => 'outline']);

        $this->assertSame('wide', $form->get('shape')->getData());
        $this->assertSame('solid', $form->get('fill')->getData());
    }

    // ... and that single key must not survive the save, now that the pair replacing it is written alongside
    public function testSubmitDropsTheLegacyStyleKey(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['facebook'], 'style' => 'outline']);

        $form->submit(['networks' => ['facebook'], 'shape' => 'circle', 'fill' => 'outline']);

        $this->assertTrue($form->isSynchronized());
        $this->assertArrayNotHasKey('style', $form->getData());
    }

    // Without any previously saved data, the networks choices stay in ShareButtonsService's own fixed order
    public function testNetworksChoicesKeepFixedOrderWhenNoDataIsSaved(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $this->assertSame(
            self::NETWORKS,
            array_values($form->get('networks')->getConfig()->getOption('choices'))
        );
    }

    // Previously saved, checked networks come first (in their saved order), then every other network in the fixed order - without this, the sortable list would reset on every page load, discarding whatever order was last dragged and saved
    public function testNetworksChoicesPutSavedOrderFirstThenRemainingNetworksInFixedOrder(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['linkedin', 'facebook'], 'shape' => 'circle', 'fill' => 'solid']);

        $this->assertSame(
            ['linkedin', 'facebook', 'bluesky', 'pinterest', 'email'],
            array_values($form->get('networks')->getConfig()->getOption('choices'))
        );
    }

    public function testSubmitValidDataPopulatesArray(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $form->submit(['networks' => ['bluesky', 'email'], 'shape' => 'ellipse', 'fill' => 'transparent']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(['networks' => ['bluesky', 'email'], 'shape' => 'ellipse', 'fill' => 'transparent', 'displayIntro' => false, 'anchor' => null], $form->getData());
    }

    // The invitation line is on unless an admin turns it off, including on a singleton saved before the setting existed - "data", not empty_data, is what does it (see the field's own comment)
    public function testDisplayIntroIsCheckedByDefaultAndFollowsWhatWasSaved(): void
    {
        $this->assertTrue($this->factory->create(ShareButtonsSettingsType::class)->get('displayIntro')->getData());
        $this->assertTrue($this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['facebook'], 'style' => 'outline'])->get('displayIntro')->getData());
        $this->assertFalse($this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['facebook'], 'displayIntro' => false])->get('displayIntro')->getData());
    }

    // An unchecked box submits no value at all, which has to end up stored as false - not as a missing key, which would read as "never set" and turn the line back on
    public function testSubmitStoresDisplayIntroAsFalseWhenUnchecked(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['facebook'], 'displayIntro' => true]);

        $form->submit(['networks' => ['facebook'], 'shape' => 'wide', 'fill' => 'solid']);

        $this->assertTrue($form->isSynchronized());
        $this->assertFalse($form->getData()['displayIntro']);
    }

    // Site-wide anchor: filled in, the band carries that id on every page, so a menu entry can link to it (see ShareButtonsExtension::renderDefaultShareButtons())
    public function testSubmitSlugifiesTheAnchor(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $form->submit(['networks' => ['email'], 'shape' => 'ellipse', 'fill' => 'solid', 'anchor' => 'Partage le ras-le-bol']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame('partage-le-ras-le-bol', $form->getData()['anchor']);
    }

    // Left empty, nothing changes for the sites that never set one: the band renders with no id, exactly as before this field existed
    public function testSubmitLeavesTheAnchorNullWhenNoneIsTyped(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $form->submit(['networks' => ['email'], 'shape' => 'ellipse', 'fill' => 'solid', 'anchor' => '']);

        $this->assertTrue($form->isSynchronized());
        $this->assertNull($form->getData()['anchor']);
    }

    public function testConfigureOptionsHasNoDataClassAndSocialTranslationDomain(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
