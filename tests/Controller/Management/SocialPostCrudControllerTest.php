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
use c975L\SocialBundle\Service\SocialPublisher;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class SocialPostCrudControllerTest extends TestCase
{
    private function configureActions(): Actions
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $key) => 'site-role-editor' === $key ? 'ROLE_EDITOR' : null);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $controller = new SocialPostCrudController(
            $configService,
            $this->createStub(SocialPublisher::class),
            $this->createStub(AdminUrlGeneratorInterface::class),
            $this->createStub(CsrfTokenManagerInterface::class),
            $translator,
        );

        // A real EasyAdmin runtime pre-populates the default actions before calling configureActions()
        return $controller->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );
    }

    public function testEveryActionDemandsSiteRoleEditor(): void
    {
        $permissions = $this->configureActions()->getAsDto(null)->getActionPermissions();

        foreach ([Action::INDEX, Action::EDIT, Action::DELETE, 'publishPost', 'prepareNextPost', 'prepareUrlPost'] as $action) {
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

    // Posts are prepared by the hourly run or the two global actions, never typed in
    public function testPostsArePreparedRatherThanCreated(): void
    {
        $actions = $this->configureActions();

        $this->assertContains(Action::NEW, $actions->getAsDto(null)->getDisabledActions());
        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareNextPost'));
        $this->assertNotNull($actions->getAsDto(Crud::PAGE_INDEX)->getAction(Crud::PAGE_INDEX, 'prepareUrlPost'));
    }
}
