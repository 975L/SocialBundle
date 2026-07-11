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
use c975L\SocialBundle\Form\Block\SocialLinksPreviewType;
use c975L\SocialBundle\Form\Block\SocialLinksType;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Repository\BlockRepository;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Asset;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\Field;
use EasyCorp\Bundle\EasyAdminBundle\Field\HiddenField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

use function Symfony\Component\Translation\t;

// Manages the site-wide "social_links" Block outside of any Page's block collection, reusing UiBundle's
// generic Block/data JSON storage - no dedicated entity/table. Its "kind" is fixed (never chosen), so
// the site-wide footer can render it via BlockRepository::findOneByKind() without it needing to be
// attached to a page first (see SiteBundle's SocialLinks Twig component). Tagged "pickable: false" in
// services.yaml, so it's deliberately absent from the normal per-page block picker - it's a singleton,
// editable only here, to avoid editors accidentally creating independent, separately-filled copies of
// it on individual pages.
class SocialLinksCrudController extends AbstractCrudController
{
    private const KIND = 'social_links';

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly BlockRepository $blockRepository,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Block::class;
    }

    // Redirects to editing the existing singleton instead of letting a second "social_links" Block
    // be created - BlockRepository::findOneByKind() (used by the front-end renderer) has no ordering,
    // so a duplicate row silently makes the newest links invisible instead of erroring
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
            ->setEntityLabelInSingular(t('label.social_links', [], 'social'))
            ->setEntityLabelInPlural(t('label.social_links', [], 'social'))
            ->setEntityPermission($this->configService->get('site-role-admin'))
            // EasyAdmin's new/edit templates apply "ea.crud.formThemes only", which drops the app-wide
            // twig.form_themes (where UiBundle registers icon_picker_theme.html.twig) entirely - without
            // this, the icon field silently falls back to a plain text input, no picker at all.
            ->addFormTheme('@c975LUi/form/icon_picker_theme.html.twig')
            // Overrides how each SocialLinkEntryType is rendered inside the "links" CollectionType -
            // see the template for why this is needed instead of EasyAdmin's default rendering.
            ->addFormTheme('@c975LSocial/management/social_link_entry_form_theme.html.twig')
            // Renders the rendered-links preview below the "links" list - see
            // social_links_preview_theme.html.twig and SocialLinksPreviewType
            ->addFormTheme('@c975LSocial/management/social_links_preview_theme.html.twig')
            // Single-row index (it's a singleton): showing Edit/Delete inline avoids an extra
            // click through the "..." dropdown to reach them.
            ->showEntityActionsInlined()
        ;
    }

    public function configureActions(Actions $actions): Actions
    {
        $role = $this->configService->get('site-role-admin');

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
        // Read once for the preview field below - only actually populated on New/Edit, where
        // getContext()->getEntity()->getInstance() is already resolved by the time configureFields()
        // runs (see AbstractCrudController::edit()/new())
        $data = $this->getContext()?->getEntity()?->getInstance()?->getData() ?? [];

        return [
            // "data" (the Block's JSON column) holds data.links, an array of {label, url, icon} - summarized
            // here as two virtual index columns, since there's no single scalar label/url to show. Their
            // property names are made up (not "data"): giving a real, Doctrine-mapped property to a generic
            // Field makes EasyAdmin silently rebuild it into an ArrayField (same reason HiddenField is used
            // below for the form), which drops formatValue() entirely and renders the raw, unformatted array.
            // A fake property name keeps it "virtual", skipping that rebuild; setValue('') then prevents
            // EasyAdmin from trying (and failing) to read that fake property straight off the entity.
            Field::new('socialLinksLabels', t('label.label', [], 'social'))
                ->onlyOnIndex()
                ->setValue('')
                ->formatValue(static fn (mixed $value, Block $entity): string => implode(', ', array_column($entity->getData()['links'] ?? [], 'label'))),
            Field::new('socialLinksUrls', t('label.url', [], 'social'))
                ->onlyOnIndex()
                ->setValue('')
                ->formatValue(static fn (mixed $value, Block $entity): string => implode(', ', array_column($entity->getData()['links'] ?? [], 'url'))),

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

            // Static preview of the links as last saved (see SocialLinksPreviewType for why it doesn't
            // live-update as entries are edited above). "mapped" => false: same reason as "data" above
            // would need if it weren't already routed entirely through setFormType() - here it's this
            // field itself that has no matching Block property, so Symfony's form data mapper would
            // otherwise crash trying to read/write it.
            Field::new('socialLinksPreview')
                ->setLabel(t('label.preview', [], 'social'))
                ->setValue('')
                ->setFormType(SocialLinksPreviewType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    'links' => $data['links'] ?? [],
                    'display_label' => $data['displayLabel'] ?? true,
                    'icon_style' => $data['iconStyle'] ?? 'minimal',
                ])
                ->onlyOnForms(),
        ];
    }
}
