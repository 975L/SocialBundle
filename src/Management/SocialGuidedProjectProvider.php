<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Controller\Management\ConfigCrudController;
use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\UiBundle\Controller\Management\ReviewCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Bundle\SecurityBundle\Security;

// This bundle's guided projects, running the 4000 block GuidedProjectProviderInterface reserves them - the same docblock stating every other bundle's, so a range is read there rather than recopied here. Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class SocialGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly Security $security,
    ) {
    }

    public function getGuidedProjects(): array
    {
        $projects = [$this->socialLinksProject()];

        // Same condition as MenuProvider's own entry: with the share buttons off site-wide, that screen isn't in the sidebar either, and a parcours walking to a screen nobody can reach reads as a broken one
        if ($this->configService->getBool($this->configService->get('social-enable-share-buttons'))) {
            $projects[] = $this->shareButtonsProject();
        }

        // Same condition as MenuProvider's own entry, for the same reason as the share buttons above. Two parcours rather than one: connecting the listing is the agency's own job (the Google keys are restricted configs, see the readme on one Cloud application shared across client sites), where reading the reviews and putting them on a page is the site's editor
        if ($this->configService->getBool($this->configService->get('ui-enable-reviews'))) {
            // The connection parcours walks screens gated on three roles, none of them implying another: the 'role' key holds one, so the conjunction is checked here, or a super-administrator missing the two others would be offered a parcours answering 403 on its very first step
            if (
                $this->security->isGranted('ROLE_SUPER_ADMIN')
                && $this->security->isGranted($this->configService->get('site-role-admin'))
                && $this->security->isGranted($this->configService->get('site-role-editor'))
            ) {
                $projects[] = $this->googleConnectProject();
            }

            $projects[] = $this->googleReviewsProject();
        }

        return $projects;
    }

    // The only parcours whose first move happens outside the site: the Google side is left to the "afficher-avis-google" help procedure, which is text and can carry links to Google's own pages, where a step's description is inserted as plain text and could not
    private function googleConnectProject(): array
    {
        return [
            'slug' => 'social-google-connect',
            'label' => 'label.guided_project_social_google_connect',
            'description' => 'description.guided_project_social_google_connect',
            'translation_domain' => 'social',
            'order' => 4030,
            // The bar its own screens state, and the only project of this bundle not stopping at site-role-editor: the two OAuth keys of step two are "restricted" configs, which ConfigCrudController hides from every user below this role (see its createIndexQueryBuilder()). A literal, exactly as ConfigBundle states it on its own restricted actions - no config holds it. The two other roles the parcours needs, site-role-admin for step one and site-role-editor for step three, do not fit here: GuidedProjectBuilder reads a single role, so getGuidedProjects() checks the conjunction before adding the project
            'role' => 'ROLE_SUPER_ADMIN',
            'steps' => [
                [
                    // Opens on ConfigBundle's own screen rather than on one of this bundle's: the two keys the connection needs are configs, and this is where the user will be working once Google has answered
                    'label' => 'label.guided_step_social_google_prerequisites',
                    'description' => 'description.guided_step_social_google_prerequisites',
                    'narration' => 'narration.guided_step_social_google_prerequisites',
                    'url' => $this->indexUrl(ConfigCrudController::class),
                ],
                [
                    // The slugs are named in the description and found with the screen's own search: a selector into another bundle's form would break on its next release
                    'label' => 'label.guided_step_social_google_credentials',
                    'description' => 'description.guided_step_social_google_credentials',
                    'narration' => 'narration.guided_step_social_google_credentials',
                ],
                [
                    // Matched on the href rather than on a marker: the entry is a plain link in the sidebar's "Avancé" submenu (see MenuProvider::getLinks()), not a rendered action of a screen
                    'label' => 'label.guided_step_social_google_connect',
                    'description' => 'description.guided_step_social_google_connect',
                    'narration' => 'narration.guided_step_social_google_connect',
                    'highlight' => 'a[href*="/social/google/connect"]',
                ],
                [
                    // No highlight: consenting leaves the site entirely and comes back through the callback's own redirect, so there is no screen left for the panel to walk - and what follows happens on its own, nightly (see SocialMaintenanceTaskProvider)
                    'label' => 'label.guided_step_social_google_sync',
                    'description' => 'description.guided_step_social_google_sync',
                    'narration' => 'narration.guided_step_social_google_sync',
                ],
            ],
        ];
    }

    // What the site's own editor does with the reviews once the listing is connected - the screen is UiBundle's, this bundle only feeding it (see ReviewSynchronizer)
    private function googleReviewsProject(): array
    {
        return [
            'slug' => 'social-google-reviews',
            'label' => 'label.guided_project_social_google_reviews',
            'description' => 'description.guided_project_social_google_reviews',
            'translation_domain' => 'social',
            'order' => 4040,
            // The bar ReviewCrudController states on its own rows, the three screens of this parcours all stopping there
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    // UiBundle's screen, not one of this bundle's: the reviews are its entity, whatever platform brought them in
                    'label' => 'label.guided_step_social_google_reviews',
                    'description' => 'description.guided_step_social_google_reviews',
                    'narration' => 'narration.guided_step_social_google_reviews',
                    'url' => $this->indexUrl(ReviewCrudController::class),
                ],
                [
                    // EasyAdmin's own edit action, renamed after the one thing the page behind it is for (see ReviewCrudController::configureActions()) - it keeps its action-edit class whatever the icon and the label become
                    'label' => 'label.guided_step_social_google_reply',
                    'description' => 'description.guided_step_social_google_reply',
                    'narration' => 'narration.guided_step_social_google_reply',
                    'highlight' => '.action-edit',
                ],
                [
                    // No highlight: the block is added from the page being composed, wherever the editor wants the reviews to show
                    'label' => 'label.guided_step_social_google_display',
                    'description' => 'description.guided_step_social_google_display',
                    'narration' => 'narration.guided_step_social_google_display',
                ],
            ],
        ];
    }

    // One list of links for the whole site, rendered wherever the block is put - not one per page
    private function socialLinksProject(): array
    {
        return [
            'slug' => 'social-links',
            'label' => 'label.guided_project_social_links',
            'description' => 'description.guided_project_social_links',
            'translation_domain' => 'social',
            'order' => 4010,
            // The role the screen itself demands (see SocialLinksCrudController's setEntityPermission/setPermission), not the dashboard's own: the two are separate roles, neither implying the other, so an admin without it would otherwise be offered a parcours whose very first step answers 403
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_links_open',
                    'description' => 'description.guided_step_social_links_open',
                    'narration' => 'narration.guided_step_social_links_open',
                    'url' => $this->indexUrl(SocialLinksCrudController::class),
                ],
                [
                    // Both at once: the list is a singleton, so the index offers "create" until it exists and "edit" ever after - whichever is on screen is the one to click
                    'label' => 'label.guided_step_social_links_edit',
                    'description' => 'description.guided_step_social_links_edit',
                    'narration' => 'narration.guided_step_social_links_edit',
                    'highlight' => '.action-new, .action-edit',
                ],
                // The four steps below follow SocialLinksType's own field order, so the panel walks down the form instead of sending the user back up it - the list of links being the last field rendered, it is also the last one pointed at
                [
                    // The <trix-editor> the field's own textarea is replaced by, not its id: TrixEditorType renders that textarea "d-none" (see UiBundle's block_theme.html.twig), so #Block_data_intro would point at something nobody sees. It's the form's only rich-text field
                    'label' => 'label.guided_step_social_links_intro',
                    'description' => 'description.guided_step_social_links_intro',
                    'narration' => 'narration.guided_step_social_links_intro',
                    'highlight' => 'trix-editor',
                ],
                [
                    'label' => 'label.guided_step_social_links_icon_style',
                    'description' => 'description.guided_step_social_links_icon_style',
                    'narration' => 'narration.guided_step_social_links_icon_style',
                    'highlight' => '[data-social-links-icon-style-select]',
                ],
                [
                    'label' => 'label.guided_step_social_links_display_label',
                    'description' => 'description.guided_step_social_links_display_label',
                    'narration' => 'narration.guided_step_social_links_display_label',
                    'highlight' => '[data-social-links-display-label-checkbox]',
                ],
                [
                    'label' => 'label.guided_step_social_links_entries',
                    'description' => 'description.guided_step_social_links_entries',
                    'narration' => 'narration.guided_step_social_links_entries',
                    'highlight' => '[data-ea-collection-field]',
                ],
                [
                    'label' => 'label.guided_step_social_links_save',
                    'narration' => 'narration.guided_step_social_links_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_social_links_place',
                    'description' => 'description.guided_step_social_links_place',
                    'narration' => 'narration.guided_step_social_links_place',
                ],
            ],
        ];
    }

    // The buttons are already on every page: what is left to decide is which networks, and how they look
    private function shareButtonsProject(): array
    {
        return [
            'slug' => 'social-share-buttons',
            'label' => 'label.guided_project_social_share_buttons',
            'description' => 'description.guided_project_social_share_buttons',
            'translation_domain' => 'social',
            'order' => 4020,
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_share_buttons_open',
                    'description' => 'description.guided_step_social_share_buttons_open',
                    'narration' => 'narration.guided_step_social_share_buttons_open',
                    'url' => $this->indexUrl(ShareButtonsSettingsCrudController::class),
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_edit',
                    'description' => 'description.guided_step_social_share_buttons_edit',
                    'narration' => 'narration.guided_step_social_share_buttons_edit',
                    'highlight' => '.action-new, .action-edit',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_networks',
                    'description' => 'description.guided_step_social_share_buttons_networks',
                    'narration' => 'narration.guided_step_social_share_buttons_networks',
                    'highlight' => '[data-share-networks-sortable]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_shape',
                    'description' => 'description.guided_step_social_share_buttons_shape',
                    'narration' => 'narration.guided_step_social_share_buttons_shape',
                    'highlight' => '[data-share-shape-select]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_fill',
                    'description' => 'description.guided_step_social_share_buttons_fill',
                    'narration' => 'narration.guided_step_social_share_buttons_fill',
                    'highlight' => '[data-share-fill-select]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_display_intro',
                    'description' => 'description.guided_step_social_share_buttons_display_intro',
                    'narration' => 'narration.guided_step_social_share_buttons_display_intro',
                    'highlight' => '[data-share-display-intro-checkbox]',
                ],
                [
                    // The field's own id, no data-* of its own: EasyAdmin names the form after the entity (see EntityDto::getName()), and "anchor" hangs under the "data" HiddenField the settings form is plugged into
                    'label' => 'label.guided_step_social_share_buttons_anchor',
                    'description' => 'description.guided_step_social_share_buttons_anchor',
                    'narration' => 'narration.guided_step_social_share_buttons_anchor',
                    'highlight' => '#Block_data_anchor',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_save',
                    'narration' => 'narration.guided_step_social_share_buttons_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_check',
                    'description' => 'description.guided_step_social_share_buttons_check',
                    'narration' => 'narration.guided_step_social_share_buttons_check',
                ],
            ],
        ];
    }

    private function indexUrl(string $controllerFqcn): string
    {
        return $this->adminUrlGenerator
            ->unsetAll()
            ->setController($controllerFqcn)
            ->setAction(Action::INDEX)
            ->generateUrl();
    }
}
