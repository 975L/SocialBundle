<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\SocialBundle\Tests\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Form\Block\ShareButtonsSettingsType;
use c975L\SocialBundle\Form\Block\ShareButtonsStylePreviewType;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

class ShareButtonsSettingsCrudControllerTest extends TestCase
{
    private function createConfigService(string $role = 'ROLE_ADMIN'): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($role);

        return $configService;
    }

    private function createTranslator(): TranslatorInterface
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return $translator;
    }

    // AdminUrlGenerator is final (can't be stubbed), but every one of its own dependencies is an
    // interface - building a real instance with those stubbed lets new() below exercise the exact
    // same URL-generation code path the real app uses, instead of faking its return value
    private function createAdminUrlGenerator(?AdminRouteGeneratorInterface $adminRouteGenerator = null): AdminUrlGenerator
    {
        $adminContextProvider = $this->createStub(AdminContextProviderInterface::class);
        $adminContextProvider->method('getContext')->willReturn(null);

        $adminControllers = $this->createStub(AdminControllerRegistryInterface::class);
        $adminControllers->method('getDashboardCount')->willReturn(1);
        $adminControllers->method('getFirstDashboard')->willReturn('App\Controller\DashboardController');
        $adminControllers->method('getFirstDashboardRoute')->willReturn('admin');

        if (null === $adminRouteGenerator) {
            $adminRouteGenerator = $this->createStub(AdminRouteGeneratorInterface::class);
            $adminRouteGenerator->method('findRouteName')->willReturn(null);
        }

        $cacheItem = $this->createStub(CacheItemInterface::class);
        $cacheItem->method('get')->willReturn(null);
        $cache = $this->createStub(CacheItemPoolInterface::class);
        $cache->method('getItem')->willReturn($cacheItem);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => $route . '?' . http_build_query($parameters)
        );

        return new AdminUrlGenerator($adminContextProvider, $urlGenerator, $adminControllers, $adminRouteGenerator, $cache);
    }

    private function createController(?Block $existingBlock, string $role = 'ROLE_ADMIN'): ShareButtonsSettingsCrudController
    {
        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturnCallback(
            static fn (string $kind) => 'share_buttons_settings' === $kind ? $existingBlock : null
        );

        return new ShareButtonsSettingsCrudController(
            $this->createConfigService($role),
            $blockRepository,
            $this->createAdminUrlGenerator(),
            $this->createTranslator(),
        );
    }

    public function testGetEntityFqcnReturnsBlockClass(): void
    {
        $this->assertSame(Block::class, ShareButtonsSettingsCrudController::getEntityFqcn());
    }

    public function testCreateEntitySetsShareButtonsSettingsKind(): void
    {
        $controller = $this->createController(null);

        $entity = $controller->createEntity(Block::class);

        $this->assertSame('share_buttons_settings', $entity->getKind());
    }

    public function testConfigureCrudSetsLabelsPermissionAndStylePreviewFormTheme(): void
    {
        $controller = $this->createController(null, 'ROLE_ADMIN');

        $dto = $controller->configureCrud(Crud::new())->getAsDto();

        $labelInSingular = $dto->getEntityLabelInSingular();
        $this->assertInstanceOf(TranslatableMessage::class, $labelInSingular);
        $this->assertSame('label.share_buttons_settings', $labelInSingular->getMessage());
        $this->assertSame('social', $labelInSingular->getDomain());

        $labelInPlural = $dto->getEntityLabelInPlural();
        $this->assertInstanceOf(TranslatableMessage::class, $labelInPlural);
        $this->assertSame('label.share_buttons_settings', $labelInPlural->getMessage());

        $this->assertSame('ROLE_ADMIN', $dto->getEntityPermission());
        $this->assertContains('@c975LSocial/management/share_buttons_style_preview_theme.html.twig', $dto->getFormThemes());
    }

    public function testConfigureActionsGrantsSiteRoleAdminOnEveryAction(): void
    {
        $controller = $this->createController(null, 'ROLE_SOCIAL_ADMIN');

        // A real EasyAdmin runtime pre-populates default actions (EDIT, DELETE...) before calling
        // configureActions() - update() below assumes EDIT/DELETE already exist on PAGE_INDEX
        $actions = Actions::new()
            ->add(Crud::PAGE_INDEX, Action::EDIT)
            ->add(Crud::PAGE_INDEX, Action::DELETE);

        $permissions = $controller->configureActions($actions)->getAsDto(null)->getActionPermissions();

        foreach ([Action::INDEX, Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL] as $action) {
            $this->assertSame('ROLE_SOCIAL_ADMIN', $permissions[$action]);
        }
    }

    public function testConfigureFieldsIndexColumnsFormatNetworksAndStyleFromBlockData(): void
    {
        $controller = $this->createController(null);
        $entity = (new Block())->setData(['networks' => ['facebook', 'bluesky'], 'style' => 'circle']);

        $fields = iterator_to_array($controller->configureFields('index'));
        $dtosByProperty = [];
        foreach ($fields as $field) {
            $dto = $field->getAsDto();
            $dtosByProperty[$dto->getProperty()] = $dto;
        }

        $this->assertSame(
            'facebook, bluesky',
            ($dtosByProperty['shareButtonsNetworks']->getFormatValueCallable())(null, $entity)
        );
        $this->assertSame(
            'circle',
            ($dtosByProperty['shareButtonsStyle']->getFormatValueCallable())(null, $entity)
        );
    }

    // A plain Field/TextField gets silently rebuilt by EasyAdmin against a JSON column - HiddenField
    // has no dedicated configurator, so setFormType() below fully takes over as intended (see the
    // class-level comment on the controller)
    public function testConfigureFieldsDataFieldUsesHiddenShareButtonsSettingsType(): void
    {
        $controller = $this->createController(null);

        $fields = iterator_to_array($controller->configureFields('edit'));
        $dataField = current(array_filter($fields, static fn ($field) => 'data' === $field->getAsDto()->getProperty()));

        $this->assertSame(ShareButtonsSettingsType::class, $dataField->getAsDto()->getFormType());
    }

    // "mapped" => false: this field has no matching Block property, so Symfony's form data mapper
    // would otherwise crash trying to read/write it (see the class-level comment on the controller)
    public function testConfigureFieldsStylePreviewFieldIsUnmappedShareButtonsStylePreviewType(): void
    {
        $controller = $this->createController(null);

        $fields = iterator_to_array($controller->configureFields('edit'));
        $previewField = current(array_filter($fields, static fn ($field) => 'shareButtonsStylePreview' === $field->getAsDto()->getProperty()));

        $this->assertSame(ShareButtonsStylePreviewType::class, $previewField->getAsDto()->getFormType());
        $this->assertFalse($previewField->getAsDto()->getFormTypeOption('mapped'));
    }

    // Redirects to editing the existing singleton instead of letting a second
    // "share_buttons_settings" Block be created (see the class-level comment on the controller);
    // the no-existing-block branch delegates to parent::new(), which needs full EasyAdmin/container
    // wiring (event dispatcher, security voter, form factory...) disproportionate to this pure-unit
    // suite, so it's left untested here
    public function testNewRedirectsToEditWhenSingletonAlreadyExists(): void
    {
        $existing = new Block();
        $idProperty = new \ReflectionProperty(Block::class, 'id');
        $idProperty->setValue($existing, 42);

        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturn($existing);

        // AdminUrlGenerator itself folds crudControllerFqcn/crudAction into the resolved route
        // name (dropping them from the query string), so the meaningful assertion for this
        // controller's own logic is that it hands the right controller FQCN, action and entity ID
        // through to that resolution step - not the exact final URL, which is EasyAdmin's concern
        $adminRouteGenerator = $this->createMock(AdminRouteGeneratorInterface::class);
        $adminRouteGenerator->expects($this->once())
            ->method('findRouteName')
            ->with($this->anything(), ShareButtonsSettingsCrudController::class, Action::EDIT)
            ->willReturn(null);

        $controller = new ShareButtonsSettingsCrudController(
            $this->createConfigService(),
            $blockRepository,
            $this->createAdminUrlGenerator($adminRouteGenerator),
            $this->createTranslator(),
        );

        $response = $controller->new(AdminContext::forTesting());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('entityId=42', $response->getTargetUrl());
    }
}
