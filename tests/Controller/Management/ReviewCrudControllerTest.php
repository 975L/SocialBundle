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
use c975L\SocialBundle\Controller\Management\ReviewCrudController;
use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Service\ReviewReplyPublisher;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Context\CrudContext;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Translation\TranslatableMessage;

class ReviewCrudControllerTest extends TestCase
{
    // Answers on the key rather than on any call: a service handing the same role back whatever it is asked for would let the screen's own key drift to another role's unnoticed
    private function createConfigService(string $role = 'ROLE_ADMIN'): ConfigServiceInterface
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(
            static fn (string $key) => 'site-role-editor' === $key ? $role : null
        );

        return $configService;
    }

    private function createPublisher(bool $supports = true): ReviewReplyPublisher
    {
        $reviewReplyPublisher = $this->createStub(ReviewReplyPublisher::class);
        $reviewReplyPublisher->method('supports')->willReturn($supports);

        return $reviewReplyPublisher;
    }

    private function createController(string $role = 'ROLE_ADMIN', ?ReviewReplyPublisher $reviewReplyPublisher = null): ReviewCrudController
    {
        return new ReviewCrudController($this->createConfigService($role), $reviewReplyPublisher ?? $this->createPublisher());
    }

    // configureFields() reads the entity being edited off the admin context, which AbstractController resolves through its container - so a screen exercised outside EasyAdmin's runtime has to be handed one
    private function createControllerOnContextOf(?Review $review, bool $supportsReply = true): ReviewCrudController
    {
        $entityDto = null === $review ? null : new EntityDto(Review::class, new ClassMetadata(Review::class), null, $review);

        $adminContextProvider = $this->createStub(AdminContextProviderInterface::class);
        $adminContextProvider->method('getContext')->willReturn(
            null === $entityDto ? null : AdminContext::forTesting(crudContext: CrudContext::forTesting(entityDto: $entityDto))
        );

        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id) => AdminContextProviderInterface::class === $id ? $adminContextProvider : null
        );

        $controller = $this->createController('ROLE_ADMIN', $this->createPublisher($supportsReply));
        $controller->setContainer($container);

        return $controller;
    }

    public function testGetEntityFqcnReturnsReviewClass(): void
    {
        $this->assertSame(Review::class, ReviewCrudController::getEntityFqcn());
    }

    public function testConfigureCrudSetsLabelsPermissionAndNewestFirstSort(): void
    {
        $dto = $this->createController('ROLE_SOCIAL_ADMIN')->configureCrud(Crud::new())->getAsDto();

        $labelInSingular = $dto->getEntityLabelInSingular();
        $this->assertInstanceOf(TranslatableMessage::class, $labelInSingular);
        $this->assertSame('label.review', $labelInSingular->getMessage());
        $this->assertSame('social', $labelInSingular->getDomain());

        $this->assertSame('ROLE_SOCIAL_ADMIN', $dto->getEntityPermission());
        $this->assertSame(['publishedAt' => 'DESC'], $dto->getDefaultSort());
    }

    // Creating a review would be fabricating one, and deleting it would only hide here what stays published on the platform - art. L111-7-2 of the French consumer code
    public function testConfigureActionsDisablesNewAndDelete(): void
    {
        $actions = $this->createController()->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $disabled = $actions->getAsDto(null)->getDisabledActions();

        $this->assertContains(Action::NEW, $disabled);
        $this->assertContains(Action::DELETE, $disabled);
        $this->assertContains(Action::DETAIL, $disabled);
    }

    public function testConfigureActionsGrantsSiteRoleEditorOnIndexAndEdit(): void
    {
        $actions = $this->createController('ROLE_SOCIAL_ADMIN')->configureActions(
            Actions::new()
                ->add(Crud::PAGE_INDEX, Action::EDIT)
                ->add(Crud::PAGE_INDEX, Action::DELETE)
        );

        $permissions = $actions->getAsDto(null)->getActionPermissions();

        $this->assertSame('ROLE_SOCIAL_ADMIN', $permissions[Action::INDEX]);
        $this->assertSame('ROLE_SOCIAL_ADMIN', $permissions[Action::EDIT]);
    }

    /**
     * @return array<string, \EasyCorp\Bundle\EasyAdminBundle\Dto\FieldDto>
     */
    private function fieldsByProperty(ReviewCrudController $controller): array
    {
        $dtos = [];

        foreach ($controller->configureFields(Crud::PAGE_EDIT) as $field) {
            $dto = $field->getAsDto();
            $dtos[$dto->getProperty()] = $dto;
        }

        return $dtos;
    }

    // Everything the author wrote is read-only: an editable rating or comment would let the back office rewrite someone else's statement
    public function testConfigureFieldsDisablesEverythingTheAuthorWrote(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1');

        $dtos = $this->fieldsByProperty($this->createControllerOnContextOf($review));

        foreach (['authorName', 'rating', 'publishedAt', 'comment'] as $property) {
            $this->assertArrayHasKey($property, $dtos);
            $this->assertTrue($dtos[$property]->getFormTypeOption('disabled'), $property . ' must not be editable');
        }
    }

    // The public reply is the one thing the site writes, so it is the one field left enabled
    public function testConfigureFieldsLeavesTheReplyEditableForASourceThatTakesOne(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1');

        $dtos = $this->fieldsByProperty($this->createControllerOnContextOf($review, true));

        $this->assertArrayHasKey('replyComment', $dtos);
        $this->assertNotTrue($dtos['replyComment']->getFormTypeOption('disabled'));
    }

    // Disabled rather than hidden for a platform taking no reply: a missing field would read as a screen that forgot it
    public function testConfigureFieldsDisablesTheReplyForASourceThatTakesNone(): void
    {
        $review = new Review()->setSource('elsewhere')->setExternalId('r1');

        $dtos = $this->fieldsByProperty($this->createControllerOnContextOf($review, false));

        $this->assertTrue($dtos['replyComment']->getFormTypeOption('disabled'));
    }

    // What Doctrine holds for the row as it was loaded, which is how an unchanged reply is told from an edited one
    private function createEntityManagerHolding(?string $storedReplyComment): EntityManagerInterface & MockObject
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        $unitOfWork->method('getOriginalEntityData')->willReturn(['replyComment' => $storedReplyComment]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getUnitOfWork')->willReturn($unitOfWork);

        return $entityManager;
    }

    public function testUpdateEntityPublishesTheReplyBeforeStoringIt(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1')->setReplyComment('Merci !');

        $reviewReplyPublisher = $this->createMock(ReviewReplyPublisher::class);
        $reviewReplyPublisher->method('supports')->willReturn(true);
        $reviewReplyPublisher->expects($this->once())->method('publish')->with($review);

        $entityManager = $this->createEntityManagerHolding(null);
        $entityManager->expects($this->once())->method('flush');

        $this->createController('ROLE_ADMIN', $reviewReplyPublisher)->updateEntity($entityManager, $review);

        $this->assertSame('Merci !', $review->getReplyComment());
        $this->assertNotNull($review->getRepliedAt());
    }

    // An emptied textarea arrives as "", which means "remove the reply" and only null says so on the platform's side
    public function testUpdateEntityNormalizesAnEmptiedReplyToNull(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1')->setReplyComment('   ');

        $entityManager = $this->createEntityManagerHolding('Merci !');
        $entityManager->expects($this->once())->method('flush');

        $this->createController('ROLE_ADMIN', $this->createPublisher())->updateEntity($entityManager, $review);

        $this->assertNull($review->getReplyComment());
        $this->assertNull($review->getRepliedAt());
    }

    // Re-saving an untouched reply would spend the platform's quota for nothing, and saving a never-answered review would delete a reply that never existed
    public function testUpdateEntityDoesNotPublishAnUnchangedReply(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1')->setReplyComment('Merci !');

        $reviewReplyPublisher = $this->createMock(ReviewReplyPublisher::class);
        $reviewReplyPublisher->method('supports')->willReturn(true);
        $reviewReplyPublisher->expects($this->never())->method('publish');

        $entityManager = $this->createEntityManagerHolding('Merci !');
        $entityManager->expects($this->once())->method('flush');

        $this->createController('ROLE_ADMIN', $reviewReplyPublisher)->updateEntity($entityManager, $review);

        $this->assertSame('Merci !', $review->getReplyComment());
    }

    // Same guard as the disabled field: a source taking no reply must not be asked to publish one, here through a forged post
    public function testUpdateEntityStoresWithoutPublishingForASourceThatTakesNoReply(): void
    {
        $review = new Review()->setSource('elsewhere')->setExternalId('r1');

        $reviewReplyPublisher = $this->createMock(ReviewReplyPublisher::class);
        $reviewReplyPublisher->method('supports')->willReturn(false);
        $reviewReplyPublisher->expects($this->never())->method('publish');

        $entityManager = $this->createEntityManagerHolding(null);
        $entityManager->expects($this->once())->method('flush');

        $this->createController('ROLE_ADMIN', $reviewReplyPublisher)->updateEntity($entityManager, $review);
    }

    // Kept here alone, a reply the platform refused would show visitors an answer its author never received
    public function testUpdateEntityLetsAPublishingFailureThroughWithoutStoringAnything(): void
    {
        $review = new Review()->setSource('google')->setExternalId('r1')->setReplyComment('Merci !');

        $reviewReplyPublisher = $this->createStub(ReviewReplyPublisher::class);
        $reviewReplyPublisher->method('supports')->willReturn(true);
        $reviewReplyPublisher->method('publish')->willThrowException(new \RuntimeException('Google refused the reply'));

        $entityManager = $this->createEntityManagerHolding(null);
        $entityManager->expects($this->never())->method('flush');

        $this->expectException(\RuntimeException::class);

        $this->createController('ROLE_ADMIN', $reviewReplyPublisher)->updateEntity($entityManager, $review);
    }
}
