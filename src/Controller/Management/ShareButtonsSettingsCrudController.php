<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Controller\Management;

use c975L\ConfigBundle\Management\EasyAdminActionHelper;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Form\Block\ShareButtonsSettingsType;
use c975L\SocialBundle\Form\Block\ShareButtonsStylePreviewType;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

use function Symfony\Component\Translation\t;

// Manages the site-wide "share_buttons_settings" singleton (networks + style used by share_buttons_default(), see ShareButtonsExtension), reusing UiBundle's generic Block/data JSON storage - no dedicated entity/table, no "ui.block" tag of its own (this singleton is never placed on a page or rendered via render_block() - same as "social_links" - see SocialLinksCrudController for the pattern this mirrors). Its pickable pointer, "share_buttons_display", is a separate block kind (see services.yaml) that lets editors drop these same settings into a specific page's block flow.
class ShareButtonsSettingsCrudController extends AbstractCrudController
{
    private const KIND = 'share_buttons_settings';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly BlockRepository $blockRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Block::class;
    }

    // Redirects to editing the existing singleton instead of letting a second "share_buttons_settings" Block be created - see SocialLinksCrudController::new() for why a duplicate row is a silent bug, not a hard error
    public function new(AdminContext $context): KeyValueStore|Response
    {
        $existing = $this->blockRepository->findOneByKind(self::KIND);
        if (null !== $existing) {
            return $this->redirect(
                $this->adminUrlGenerator
                    ->setController(self::class)
                    ->setAction(Action::EDIT)
                    ->setEntityId($existing->getId())
                    ->generateUrl()
            );
        }

        return parent::new($context);
    }

    public function createIndexQueryBuilder(...$args): QueryBuilder
    {
        return parent::createIndexQueryBuilder(...$args)
            ->andWhere('entity.kind = :kind')
            ->setParameter('kind', self::KIND)
        ;
    }

    public function createEntity(string $entityFqcn): Block
    {
        return (new Block())->setKind(self::KIND);
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular(t('label.share_buttons_settings', [], 'social'))
            ->setEntityLabelInPlural(t('label.share_buttons_settings', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-admin'))
            // Renders the style preview below the style <select> - see share_buttons_style_preview_theme.html.twig and ShareButtonsStylePreviewType
            ->addFormTheme('@c975LSocial/management/share_buttons_style_preview_theme.html.twig')
            // Single-row index (it's a singleton): showing Edit/Delete inline avoids an extra click through the "..." dropdown to reach them.
            ->showEntityActionsInlined()
            ->overrideTemplate('crud/index', '@c975LSocial/management/share_buttons_settings_crud_index.html.twig')
            ->overrideTemplate('crud/edit', '@c975LSocial/management/share_buttons_settings_crud_edit.html.twig')
            ->overrideTemplate('crud/new', '@c975LSocial/management/share_buttons_settings_crud_new.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

        // Lets the admin back out of a create/edit without saving - mirrors EasyAdmin's own built-in actions (linkToCrudAction targeting INDEX, same as Action::INDEX itself)
        $cancelAction = Action::new('cancel', $this->translator->trans('action.cancel', [], 'EasyAdminBundle'), 'fa fa-times')
            ->linkToCrudAction(Action::INDEX)
            ->addCssClass('btn btn-secondary');

        return $actions
            ->add(Crud::PAGE_NEW, $cancelAction)
            ->add(Crud::PAGE_EDIT, $cancelAction)
            ->update(Crud::PAGE_INDEX, Action::EDIT, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.edit', [], 'EasyAdminBundle'),
            ))
            ->update(Crud::PAGE_INDEX, Action::DELETE, fn (Action $action) => EasyAdminActionHelper::toIconOnly(
                $action,
                $this->translator->trans('action.delete', [], 'EasyAdminBundle'),
            ))
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::NEW, $role)
            ->setPermission(Action::EDIT, $role)
            ->setPermission(Action::DELETE, $role)
            // Detail adds no information beyond what edit already shows
            ->disable(Action::DETAIL)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            // Virtual index columns summarizing "data" (see SocialLinksCrudController for why these use a fake property name + setValue('') instead of a real, Doctrine-mapped one)
            Field::new('shareButtonsNetworks', t('label.networks', [], 'social'))
                ->onlyOnIndex()
                ->setValue('')
                ->formatValue(static fn (mixed $value, Block $entity): string => implode(', ', $entity->getData()['networks'] ?? [])),
            Field::new('shareButtonsStyle', t('label.style', [], 'social'))
                ->onlyOnIndex()
                ->setValue('')
                ->formatValue(static fn (mixed $value, Block $entity): string => $entity->getData()['style'] ?? ''),

            // HiddenField, not Field/TextField: see SocialLinksCrudController for why a plain Field gets silently rebuilt into an ArrayField/TextField that crashes against a JSON column
            HiddenField::new('data')
                ->setLabel(t('label.share_buttons_settings', [], 'social'))
                ->setFormType(ShareButtonsSettingsType::class)
                ->onlyOnForms(),

            // Live preview updated client-side (see assets/js/share-buttons-preview.js) as the style <select> and the "networks" checkboxes above change, or are drag-reordered (see assets/js/share-buttons-networks-sort.js). Field::setTemplatePath() is only honored on index/detail pages, never on New/Edit (EasyAdmin's CrudFormType always builds a real Symfony form field there instead) - ShareButtonsStylePreviewType + its form theme widget block render the actual preview markup. "mapped" => false: unlike onlyOnIndex() fields above, this one is onlyOnForms(), so EasyAdmin builds a real Symfony form field for it (CrudFormType::buildForm()) - setValue('') alone only overrides EasyAdmin's own display value, but Symfony's form data mapper still tries reading the (fake, non-existent) property off Block directly, crashing with "Can't get a way to read the property". Unmapping it skips that read entirely.
            Field::new('shareButtonsStylePreview')
                ->setLabel(t('label.style_preview', [], 'social'))
                ->setValue('')
                ->setFormType(ShareButtonsStylePreviewType::class)
                ->setFormTypeOption('mapped', false)
                ->onlyOnForms(),
        ];
    }
}
