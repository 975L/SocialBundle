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
use c975L\SocialBundle\Controller\Management\SocialPostCrudController;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Service\SocialMediaChecker;
use c975L\SocialBundle\Service\SocialMediaPicker;
use c975L\SocialBundle\Service\SocialPlanner;
use c975L\SocialBundle\Service\SocialPublisher;
use c975L\SocialBundle\Service\SocialSeriesGenerator;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Dto\BatchActionDto;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialPostCrudControllerTest extends TestCase
{
    private function controller(?EntityManagerInterface $entityManager = null): SocialPostCrudController
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/admin');

        return new SocialPostCrudController(
            $configService,
            $this->createStub(SocialPublisher::class),
            $adminUrlGenerator,
            $this->createStub(CsrfTokenManagerInterface::class),
            $translator,
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $this->createStub(SocialPlanner::class),
            $this->createStub(SocialMediaChecker::class),
            $this->createStub(SocialSeriesGenerator::class),
            $this->createStub(SocialMediaPicker::class),
        );
    }

    private function configureActions(): Actions
    {
        // A real EasyAdmin runtime pre-populates the default actions before calling configureActions()
        return $this->controller()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );
    }

    public function testEveryActionDemandsSiteRoleEditor(): void
    {
        $permissions = $this->configureActions()->getAsDto(null)->getActionPermissions();

        foreach ([Action::INDEX, Action::EDIT, Action::DELETE, 'publishPost', 'approvePost', 'unapprovePost', 'prepareUrlPost', 'generateSeries', 'approveSelection', 'pickMedia'] as $action) {
            $this->assertSame('ROLE_EDITOR', $permissions[$action], $action);
        }
    }

    // On the post's page a link would send the text as saved, not as just corrected: "Publish" is offered on the list only
    public function testPublishIsOfferedOnTheListOnly(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'publishPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'publishPost'));
    }

    // The libraries' medias are added to a saved post, from its page
    public function testPickingMediasIsOfferedOnThePostsPage(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_EDIT)->getAction(Crud::PAGE_EDIT, 'pickMedia'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'pickMedia'));
    }

    // A page's address prepares a draft from the list, the hourly run and its batch being gone
    public function testPostsArePreparedFromAnAddress(): void
    {
        $actions = $this->configureActions();

        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareUrlPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareNextPost'));
        $this->assertNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareDrafts'));
    }

    // Approving is a gesture of the list, next to "Publish", the post's page saving the planned moment with its texts
    public function testApprovingIsOfferedOnTheList(): void
    {
        $actions = $this->configureActions()->getAsDto(Crud::PAGE_INDEX);

        $this->assertNotNull($actions->getAction(Crud::PAGE_INDEX, 'approvePost'));
        $this->assertNotNull($actions->getAction(Crud::PAGE_INDEX, 'unapprovePost'));
    }

    // A selection sent without EasyAdmin's batch token, from another site, approves nothing
    public function testApprovingASelectionDemandsItsToken(): void
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturn(true);
        $csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $csrfTokenManager->expects($this->once())->method('isTokenValid')
            ->with(new CsrfToken('ea-batch-action-approveSelection-' . SocialPost::class, 'forged'))
            ->willReturn(false);
        $services = ['security.authorization_checker' => $authorizationChecker, 'security.csrf.token_manager' => $csrfTokenManager];
        $container = $this->createStub(ContainerInterface::class);
        $container->method('has')->willReturnCallback(static fn (string $id): bool => isset($services[$id]));
        $container->method('get')->willReturnCallback(static fn (string $id) => $services[$id] ?? null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects($this->never())->method('find');
        $entityManager->expects($this->never())->method('flush');
        $controller = $this->controller($entityManager);
        $controller->setContainer($container);

        $controller->approveSelection(new BatchActionDto('approveSelection', [1, 2], SocialPost::class, 'forged'));
    }
}
