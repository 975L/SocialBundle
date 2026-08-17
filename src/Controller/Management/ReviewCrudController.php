<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Entity\Review;
use c975L\SocialBundle\Service\ReviewReplyPublisher;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

use function Symfony\Component\Translation\t;

// Imported reviews, shown and answered - never created, edited or hidden here. A review is its author's statement: rewriting it would falsify it, and dropping the ones that displease would be exactly what L111-7-2 forbids. An abusive review is reported to the platform, where it also has to disappear
class ReviewCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ReviewReplyPublisher $reviewReplyPublisher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Review::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.review', [], 'social'))
            ->setEntityLabelInPlural(t('label.reviews', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-editor'))
            ->setDefaultSort(['publishedAt' => 'DESC'])
            ->showEntityActionsInlined()
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-editor');

        return $actions
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::EDIT, $role)
            // Creating a review would be fabricating one, and deleting it would only hide here what stays published on the platform
            ->disable(Action::NEW, Action::DELETE, Action::DETAIL)
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('authorName', t('label.review_author', [], 'social'))->setDisabled();
        yield IntegerField::new('rating', t('label.review_rating', [], 'social'))->setDisabled();
        yield DateTimeField::new('publishedAt', t('label.review_published_at', [], 'social'))->setDisabled();
        yield TextField::new('source', t('label.review_source', [], 'social'))->setDisabled()->hideOnForm();
        yield TextareaField::new('comment', t('label.review_comment', [], 'social'))->setDisabled();

        // Disabled rather than hidden for a platform taking no reply: the field says the answer is not offered here, where a missing one would read as a screen that forgot it
        $review = $this->getContext()?->getEntity()->getInstance();
        yield TextareaField::new('replyComment', t('label.review_reply', [], 'social'))
            ->setHelp(t('label.review_reply_help', [], 'social'))
            ->setDisabled($review instanceof Review && !$this->reviewReplyPublisher->supports($review))
            ->hideOnIndex()
        ;
    }

    // The answer goes to the platform before it is stored: kept here alone, it would show visitors a reply its author never received
    #[\Override]
    public function updateEntity(EntityManagerInterface $entityManager, mixed $entityInstance): void
    {
        // Same guard as the disabled field in configureFields(): a source taking no reply must not be asked to publish one, here through a forged post
        if ($entityInstance instanceof Review && $this->reviewReplyPublisher->supports($entityInstance)) {
            $entityInstance->setReplyComment($this->normalize($entityInstance->getReplyComment()));

            // Only a changed reply is sent: re-publishing an identical one would spend the platform's quota for nothing, and saving a never-answered review would delete a reply that never existed
            $original = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['replyComment'] ?? null;

            if ($original !== $entityInstance->getReplyComment()) {
                $this->reviewReplyPublisher->publish($entityInstance);
                $entityInstance->setRepliedAt(null === $entityInstance->getReplyComment() ? null : new \DateTimeImmutable());
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    // An emptied textarea arrives as "" and means "remove the reply", which only null says on the platform's side
    private function normalize(?string $comment): ?string
    {
        $comment = null === $comment ? null : trim($comment);

        return '' === $comment ? null : $comment;
    }
}
