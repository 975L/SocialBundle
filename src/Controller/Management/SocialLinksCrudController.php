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
use c975L\SocialBundle\Form\Block\SocialLinksType;
use c975L\UiBundle\Entity\Block;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;

use function Symfony\Component\Translation\t;

// Manages the site-wide "social_links" Block outside of any Page's block collection, reusing UiBundle's
// generic Block/data JSON storage - no dedicated entity/table. Its "kind" is fixed (never chosen), so
// the site-wide footer can render it via BlockRepository::findOneByKind() without it needing to be
// attached to a page first (see SiteBundle's SocialLinks Twig component). The same "social_links" kind
// stays selectable from the normal per-page block picker too, if ever wanted on a specific page in
// addition to the footer.
class SocialLinksCrudController extends AbstractCrudController
{
    private const KIND = 'social_links';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Block::class;
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
            ->setEntityLabelInSingular(t('label.social_links', [], 'social'))
            ->setEntityLabelInPlural(t('label.social_links', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-needed'))
            // EasyAdmin's new/edit templates apply "ea.crud.formThemes only", which drops the app-wide
            // twig.form_themes (where UiBundle registers icon_picker_theme.html.twig) entirely - without
            // this, the icon field silently falls back to a plain text input, no picker at all.
            ->addFormTheme('@c975LUi/form/icon_picker_theme.html.twig')
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-needed');

        return $actions
            ->setPermission(Action::INDEX, $role)
            ->setPermission(Action::NEW, $role)
            ->setPermission(Action::EDIT, $role)
            ->setPermission(Action::DELETE, $role)
            ->setPermission(Action::DETAIL, $role)
        ;
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),

            // HiddenField, not Field/TextField: "data" is a Doctrine JSON column, so a plain
            // Field::new() gets silently rebuilt by EasyAdmin into an ArrayField, which force-injects
            // CollectionType-style "entry_type"/"allow_add"/... options that SocialLinksType doesn't
            // declare, crashing the form. TextField avoids that rebuild but its own configurator then
            // throws instead, because it requires the raw value to be a string/Stringable, and "data"
            // is an array. HiddenField has no dedicated configurator at all, so nothing inspects or
            // reshapes the value or options; setFormType() below fully takes over as intended.
            // HiddenField has no dedicated JS of its own, unlike CollectionField/ArrayField which enqueue
            // "field-collection.js" (the script behind the "add new item"/"delete" buttons). Since the
            // nested CollectionType inside SocialLinksType renders those same buttons through EasyAdmin's
            // generic form theme, the script must be added manually here or clicking "add" does nothing.
            HiddenField::new('data')
                ->setLabel(t('label.social_links', [], 'social'))
                ->setFormType(SocialLinksType::class)
                ->addJsFiles(Asset::fromEasyAdminAssetPackage('field-collection.js')->onlyOnForms())
                ->onlyOnForms(),
        ];
    }
}
