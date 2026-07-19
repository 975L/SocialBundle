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
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

class ShareButtonsSettingsTypeTest extends TypeTestCase
{
    private const NETWORKS = ['facebook', 'bluesky', 'linkedin', 'pinterest', 'email'];
    private const STYLES = ['distinct', 'ellipse', 'circle'];

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
        $shareButtonsService->method('getStyles')->willReturn(self::STYLES);

        return $shareButtonsService;
    }

    protected function getExtensions(): array
    {
        return [new PreloadedExtension([new ShareButtonsSettingsType($this->createShareButtonsService())], [])];
    }

    // Both fields are (re)built from scratch on PRE_SET_DATA (see the class-level comment for why), which the form factory always triggers once on creation - so they must already be present even before any explicit submit/setData call
    public function testBuildFormAddsNetworksAndStyleFieldsOnCreation(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $this->assertSame(['networks', 'style'], array_keys($form->all()));
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

    public function testStyleFieldIsSingleChoiceOfAllStylesWithTranslatedLabels(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $styleField = $form->get('style');
        $this->assertInstanceOf(ChoiceType::class, $styleField->getConfig()->getType()->getInnerType());
        $this->assertFalse($styleField->getConfig()->getOption('expanded'));
        $this->assertSame(
            ['label.style_distinct' => 'distinct', 'label.style_ellipse' => 'ellipse', 'label.style_circle' => 'circle'],
            $styleField->getConfig()->getOption('choices')
        );
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
        $form = $this->factory->create(ShareButtonsSettingsType::class, ['networks' => ['linkedin', 'facebook'], 'style' => 'circle']);

        $this->assertSame(
            ['linkedin', 'facebook', 'bluesky', 'pinterest', 'email'],
            array_values($form->get('networks')->getConfig()->getOption('choices'))
        );
    }

    public function testSubmitValidDataPopulatesArray(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $form->submit(['networks' => ['bluesky', 'email'], 'style' => 'ellipse']);

        $this->assertTrue($form->isSynchronized());
        $this->assertSame(['networks' => ['bluesky', 'email'], 'style' => 'ellipse'], $form->getData());
    }

    public function testConfigureOptionsHasNoDataClassAndSocialTranslationDomain(): void
    {
        $form = $this->factory->create(ShareButtonsSettingsType::class);

        $this->assertNull($form->getConfig()->getOption('data_class'));
        $this->assertSame('social', $form->getConfig()->getOption('translation_domain'));
    }
}
