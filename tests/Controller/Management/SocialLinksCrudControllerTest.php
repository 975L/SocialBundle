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
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Form\Block\SocialLinksPreviewType;
use c975L\SocialBundle\Form\Block\SocialLinksType;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Registry\AdminControllerRegistryInterface;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Router\AdminRouteGeneratorInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialLinksCrudControllerTest extends TestCase
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

    private function createController(?Block $existingBlock, string $role = 'ROLE_ADMIN'): SocialLinksCrudController
    {
        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturnCallback(
            static fn (string $kind) => 'social_links' === $kind ? $existingBlock : null
        );

        return new SocialLinksCrudController(
            $this->createConfigService($role),
            $blockRepository,
            $this->createAdminUrlGenerator(),
            $this->createTranslator(),
        );
    }

    // configureFields() unconditionally reads getContext()->getEntity()->getInstance()->getData()
    // to seed the preview field's options - EasyAdmin ships AdminContext::forTesting()/
    // CrudContext::forTesting() precisely to build a real context without a full container/kernel,
    // wrapped here in a Psr\Container\ContainerInterface stub for AbstractController::getContext()
    // to fetch it from
    private function setContextEntity(SocialLinksCrudController $controller, ?Block $entity): void
    {
        // AdminContext::getEntity() throws if the CrudContext's EntityDto itself is null (it's
        // meant to guard "no CRUD operation at all", not "no entity instance yet") - a real
        // "new"/"edit" page always has an EntityDto, only its instance is null before
        // createEntity() runs, so that's what a not-yet-saved singleton is modeled as here
        $entityDto = new EntityDto(Block::class, new ClassMetadata(Block::class), null, $entity);
        $adminContext = AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto));

        $adminContextProvider = $this->createStub(AdminContextProviderInterface::class);
        $adminContextProvider->method('getContext')->willReturn($adminContext);

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($adminContextProvider);

        $controller->setContainer($container);
    }

    public function testGetEntityFqcnReturnsBlockClass(): void
    {
        $this->assertSame(Block::class, SocialLinksCrudController::getEntityFqcn());
    }

    public function testCreateEntitySetsSocialLinksKind(): void
    {
        $controller = $this->createController(null);

        $entity = $controller->createEntity(Block::class);

        $this->assertSame('social_links', $entity->getKind());
    }

    public function testConfigureCrudSetsLabelsPermissionAndFormThemes(): void
    {
        $controller = $this->createController(null, 'ROLE_ADMIN');

        $dto = $controller->configureCrud(Crud::new())->getAsDto();

        $labelInSingular = $dto->getEntityLabelInSingular();
        $this->assertInstanceOf(TranslatableMessage::class, $labelInSingular);
        $this->assertSame('label.social_links', $labelInSingular->getMessage());
        $this->assertSame('social', $labelInSingular->getDomain());

        $this->assertSame('ROLE_ADMIN', $dto->getEntityPermission());
        $this->assertContains('@c975LUi/form/icon_picker_theme.html.twig', $dto->getFormThemes());
        $this->assertContains('@c975LSocial/management/social_link_entry_form_theme.html.twig', $dto->getFormThemes());
        $this->assertContains('@c975LSocial/management/social_links_preview_theme.html.twig', $dto->getFormThemes());
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

    public function testConfigureFieldsIndexColumnsJoinLabelsAndUrlsFromLinksData(): void
    {
        $controller = $this->createController(null);
        $this->setContextEntity($controller, null);
        $entity = (new Block())->setData(['links' => [
            ['label' => 'Facebook', 'url' => 'https://facebook.com/975l', 'icon' => 'facebook'],
            ['label' => 'Bluesky', 'url' => 'https://bsky.app/975l', 'icon' => 'bluesky'],
        ]]);

        $fields = iterator_to_array($controller->configureFields('index'));
        $dtosByProperty = [];
        foreach ($fields as $field) {
            $dto = $field->getAsDto();
            $dtosByProperty[$dto->getProperty()] = $dto;
        }

        $this->assertSame(
            'Facebook, Bluesky',
            ($dtosByProperty['socialLinksLabels']->getFormatValueCallable())(null, $entity)
        );
        $this->assertSame(
            'https://facebook.com/975l, https://bsky.app/975l',
            ($dtosByProperty['socialLinksUrls']->getFormatValueCallable())(null, $entity)
        );
    }

    // HiddenField, not Field/TextField: "data" is a Doctrine JSON column, a plain Field would get
    // silently rebuilt into an ArrayField that crashes against SocialLinksType's options (see the
    // class-level comment on the controller)
    public function testConfigureFieldsDataFieldUsesHiddenSocialLinksTypeWithCollectionJs(): void
    {
        $controller = $this->createController(null);
        $this->setContextEntity($controller, null);

        $fields = iterator_to_array($controller->configureFields('edit'));
        $dataField = current(array_filter($fields, static fn ($field) => 'data' === $field->getAsDto()->getProperty()));

        $this->assertSame(SocialLinksType::class, $dataField->getAsDto()->getFormType());
    }

    // Before any singleton has ever been saved, the preview field falls back to an empty link
    // list, visible labels and the "minimal" icon style
    public function testConfigureFieldsPreviewFieldDefaultsWhenNoEntityDataYet(): void
    {
        $controller = $this->createController(null);
        $this->setContextEntity($controller, null);

        $fields = iterator_to_array($controller->configureFields('new'));
        $previewField = current(array_filter($fields, static fn ($field) => 'socialLinksPreview' === $field->getAsDto()->getProperty()));
        $options = $previewField->getAsDto()->getFormTypeOptions();

        $this->assertSame(SocialLinksPreviewType::class, $previewField->getAsDto()->getFormType());
        $this->assertFalse($options['mapped']);
        $this->assertSame([], $options['links']);
        $this->assertTrue($options['display_label']);
        $this->assertSame('minimal', $options['icon_style']);
    }

    // Once the singleton exists, the preview is seeded from its last-saved data instead
    public function testConfigureFieldsPreviewFieldReadsSavedEntityData(): void
    {
        $links = [['label' => 'Facebook', 'url' => 'https://facebook.com/975l', 'icon' => 'facebook']];
        $entity = (new Block())->setData(['links' => $links, 'displayLabel' => false, 'iconStyle' => 'colored']);

        $controller = $this->createController($entity);
        $this->setContextEntity($controller, $entity);

        $fields = iterator_to_array($controller->configureFields('edit'));
        $previewField = current(array_filter($fields, static fn ($field) => 'socialLinksPreview' === $field->getAsDto()->getProperty()));
        $options = $previewField->getAsDto()->getFormTypeOptions();

        $this->assertSame($links, $options['links']);
        $this->assertFalse($options['display_label']);
        $this->assertSame('colored', $options['icon_style']);
    }

    // Redirects to editing the existing singleton instead of letting a second "social_links"
    // Block be created (see the class-level comment on the controller); the no-existing-block
    // branch delegates to parent::new(), which needs full EasyAdmin/container wiring (event
    // dispatcher, security voter, form factory...) disproportionate to this pure-unit suite, so
    // it's left untested here
    public function testNewRedirectsToEditWhenSingletonAlreadyExists(): void
    {
        $existing = new Block();
        $idProperty = new \ReflectionProperty(Block::class, 'id');
        $idProperty->setValue($existing, 7);

        $blockRepository = $this->createStub(BlockRepository::class);
        $blockRepository->method('findOneByKind')->willReturn($existing);

        // AdminUrlGenerator itself folds crudControllerFqcn/crudAction into the resolved route
        // name (dropping them from the query string), so the meaningful assertion for this
        // controller's own logic is that it hands the right controller FQCN, action and entity ID
        // through to that resolution step - not the exact final URL, which is EasyAdmin's concern
        $adminRouteGenerator = $this->createMock(AdminRouteGeneratorInterface::class);
        $adminRouteGenerator->expects($this->once())
            ->method('findRouteName')
            ->with($this->anything(), SocialLinksCrudController::class, Action::EDIT)
            ->willReturn(null);

        $controller = new SocialLinksCrudController(
            $this->createConfigService(),
            $blockRepository,
            $this->createAdminUrlGenerator($adminRouteGenerator),
            $this->createTranslator(),
        );

        $response = $controller->new(AdminContext::forTesting());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringContainsString('entityId=7', $response->getTargetUrl());
    }
}
