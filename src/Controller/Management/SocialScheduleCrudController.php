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
use c975L\SocialBundle\Entity\SocialSchedule;
use c975L\SocialBundle\Service\SocialPublisher;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TimeField;

use function Symfony\Component\Translation\t;

// The publication slots: each one a time of day, the sources it draws from and the networks it posts on - a scheduled task of its own, taken into account within the hour (see SocialMaintenanceTaskProvider)
class SocialScheduleCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly SocialPublisher $socialPublisher,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return SocialSchedule::class;
    }

    #[\Override]
    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.social_schedule', [], 'social'))
            ->setEntityLabelInPlural(t('label.social_schedules', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-editor'))
            ->setDefaultSort(['time' => 'ASC'])
            ->showEntityActionsInlined()
            // Carries the screen's own explanatory text, the very key the sidebar entry reuses as its onboarding description (see MenuProvider)
            ->overrideTemplate('crud/index', '@c975LSocial/management/social_schedule_crud_index.html.twig')
        ;
    }

    #[\Override]
    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-editor');

        return $actions
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::NEW, $role)
            ->setPermission(Action::EDIT, $role)
            ->setPermission(Action::DELETE, $role)
            ->disable(Action::DETAIL)
        ;
    }

    #[\Override]
    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', t('label.social_schedule_name', [], 'social'));
        yield TimeField::new('time', t('label.social_schedule_time', [], 'social'))
            ->setFormat('HH:mm')
            ->setFormTypeOption('input', 'datetime_immutable')
            ->setHelp(t('label.social_schedule_time_help', [], 'social'))
        ;
        yield BooleanField::new('enabled', t('label.social_schedule_enabled', [], 'social'))->renderAsSwitch(false);
        yield ChoiceField::new('sources', t('label.social_schedule_sources', [], 'social'))
            ->setChoices(array_flip($this->socialPublisher->getSourceChoices()))
            ->allowMultipleChoices()
            ->autocomplete()
            ->setHelp(t('label.social_schedule_sources_help', [], 'social'))
        ;
        yield ChoiceField::new('networks', t('label.social_schedule_networks', [], 'social'))
            ->setChoices(array_combine(array_map(ucfirst(...), $this->socialPublisher->getNetworkNames()), $this->socialPublisher->getNetworkNames()))
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp(t('label.social_schedule_networks_help', [], 'social'))
        ;
        yield TextareaField::new('text', t('label.social_schedule_text', [], 'social'))
            ->hideOnIndex()
            ->setHelp(t('label.social_schedule_text_help', [], 'social'))
        ;
    }
}
