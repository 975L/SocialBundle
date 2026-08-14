<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Management\GuidedProjectProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;

// This bundle's guided projects, continuing the order sequence after ConfigBundle (10-40), SiteBundle (50-80) and UiBundle (90-110), running 130-135 to leave GalleryBundle's own 140 clear. Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class SocialGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
    ) {
    }

    public function getGuidedProjects(): array
    {
        $projects = [$this->socialLinksProject()];

        // Same condition as MenuProvider's own entry: with the share buttons off site-wide, that screen isn't in the sidebar either, and a parcours walking to a screen nobody can reach reads as a broken one
        if ($this->configService->getBool($this->configService->get('social-enable-share-buttons'))) {
            $projects[] = $this->shareButtonsProject();
        }

        return $projects;
    }

    // One list of links for the whole site, rendered wherever the block is put - not one per page
    private function socialLinksProject(): array
    {
        return [
            'slug' => 'social-links',
            'label' => 'label.guided_project_social_links',
            'description' => 'description.guided_project_social_links',
            'translation_domain' => 'social',
            'order' => 130,
            // The role the screen itself demands (see SocialLinksCrudController's setEntityPermission/setPermission), not the dashboard's own: the two are separate roles, neither implying the other, so an admin without it would otherwise be offered a parcours whose very first step answers 403
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_links_open',
                    'description' => 'description.guided_step_social_links_open',
                    'url' => $this->indexUrl(SocialLinksCrudController::class),
                ],
                [
                    // Both at once: the list is a singleton, so the index offers "create" until it exists and "edit" ever after - whichever is on screen is the one to click
                    'label' => 'label.guided_step_social_links_edit',
                    'description' => 'description.guided_step_social_links_edit',
                    'highlight' => '.action-new, .action-edit',
                ],
                // The four steps below follow SocialLinksType's own field order, so the panel walks down the form instead of sending the user back up it - the list of links being the last field rendered, it is also the last one pointed at
                [
                    // The <trix-editor> the field's own textarea is replaced by, not its id: TrixEditorType renders that textarea "d-none" (see UiBundle's block_theme.html.twig), so #Block_data_intro would point at something nobody sees. It's the form's only rich-text field
                    'label' => 'label.guided_step_social_links_intro',
                    'description' => 'description.guided_step_social_links_intro',
                    'highlight' => 'trix-editor',
                ],
                [
                    'label' => 'label.guided_step_social_links_icon_style',
                    'description' => 'description.guided_step_social_links_icon_style',
                    'highlight' => '[data-social-links-icon-style-select]',
                ],
                [
                    'label' => 'label.guided_step_social_links_display_label',
                    'description' => 'description.guided_step_social_links_display_label',
                    'highlight' => '[data-social-links-display-label-checkbox]',
                ],
                [
                    'label' => 'label.guided_step_social_links_entries',
                    'description' => 'description.guided_step_social_links_entries',
                    'highlight' => '[data-ea-collection-field]',
                ],
                [
                    'label' => 'label.guided_step_social_links_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_social_links_place',
                    'description' => 'description.guided_step_social_links_place',
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
            'order' => 135,
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_share_buttons_open',
                    'description' => 'description.guided_step_social_share_buttons_open',
                    'url' => $this->indexUrl(ShareButtonsSettingsCrudController::class),
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_edit',
                    'description' => 'description.guided_step_social_share_buttons_edit',
                    'highlight' => '.action-new, .action-edit',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_networks',
                    'description' => 'description.guided_step_social_share_buttons_networks',
                    'highlight' => '[data-share-networks-sortable]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_shape',
                    'description' => 'description.guided_step_social_share_buttons_shape',
                    'highlight' => '[data-share-shape-select]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_fill',
                    'description' => 'description.guided_step_social_share_buttons_fill',
                    'highlight' => '[data-share-fill-select]',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_display_intro',
                    'description' => 'description.guided_step_social_share_buttons_display_intro',
                    'highlight' => '[data-share-display-intro-checkbox]',
                ],
                [
                    // The field's own id, no data-* of its own: EasyAdmin names the form after the entity (see EntityDto::getName()), and "anchor" hangs under the "data" HiddenField the settings form is plugged into
                    'label' => 'label.guided_step_social_share_buttons_anchor',
                    'description' => 'description.guided_step_social_share_buttons_anchor',
                    'highlight' => '#Block_data_anchor',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    'label' => 'label.guided_step_social_share_buttons_check',
                    'description' => 'description.guided_step_social_share_buttons_check',
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
