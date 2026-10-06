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
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Controller\Management\SocialPostCrudController;
use c975L\UiBundle\Controller\Management\ReviewCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

// This bundle's guided projects, running the 4000 block GuidedProjectProviderInterface reserves them - the same docblock stating every other bundle's, so a range is read there rather than recopied here. Only the opening step of each carries an url: from there the parcours walks the screen the user has been sent to, highlighting the button or the field they are meant to use next - one they click themselves, which brings the panel back on that very step (see ConfigBundle's assets/js/guided-project.js)
class SocialGuidedProjectProvider implements GuidedProjectProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly AdminUrlGeneratorInterface $adminUrlGenerator,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function getGuidedProjects(): array
    {
        // Every parcours whatever the site uses, the screens they walk being always in the sidebar. Google's split in two: connecting the listing is the agency's own job (the Google keys are restricted configs, see the readme on one Cloud application shared across client sites), reading the reviews the site's editor - and likewise Meta's
        return [
            $this->socialLinksProject(),
            $this->shareButtonsProject(),
            $this->googleConnectProject(),
            $this->googleReviewsProject(),
            $this->seriesProject(),
            $this->metaConnectProject(),
            $this->socialPostsProject(),
            $this->blueskyConnectProject(),
            $this->linkedinConnectProject(),
            $this->socialCalendarProject(),
        ];
    }

    // A connection parcours walks screens gated on three roles, none of them implying another: GuidedProjectBuilder requires every role listed
    private function restrictedConfigRoles(): array
    {
        return ['ROLE_SUPER_ADMIN', $this->configService->get('site-role-admin'), $this->configService->get('site-role-editor')];
    }

    // The "Connexions" entry of the sidebar's "Social" submenu, matched on the href rather than on a marker - the step a connection parcours takes before pointing at its tile's button
    private function connectionsStep(): array
    {
        return [
            'label' => 'label.guided_step_social_connections_open',
            'description' => 'description.guided_step_social_connections_open',
            'narration' => 'narration.guided_step_social_connections_open',
            'highlight' => 'a[href*="/social-connections"]',
        ];
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
            // The bar its own screens state, and the only project of this bundle not stopping at site-role-editor: the two OAuth keys of step two are "restricted" configs, which ConfigCrudController hides from every user below this role (see its createIndexQueryBuilder()). A literal, exactly as ConfigBundle states it on its own restricted actions - no config holds it. Joined by site-role-admin for step one and site-role-editor for step three, see restrictedConfigRoles()
            'role' => $this->restrictedConfigRoles(),
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
                $this->connectionsStep(),
                [
                    // The tile's own button, matched on the route it links to
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

    // A series of drafts at a steady pace, walked down its form - the screen also opened from the posts' list and from the calendar
    private function seriesProject(): array
    {
        return [
            'slug' => 'social-series',
            'label' => 'label.guided_project_social_series',
            'description' => 'description.guided_project_social_series',
            'translation_domain' => 'social',
            'order' => 4050,
            // The bar SocialPostCrudController::generateSeries() states
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_series_open',
                    'description' => 'description.guided_step_social_series_open',
                    'narration' => 'narration.guided_step_social_series_open',
                    'url' => $this->adminUrlGenerator
                        ->unsetAll()
                        ->setController(SocialPostCrudController::class)
                        ->setAction('generateSeries')
                        ->generateUrl(),
                ],
                [
                    // The fields' own ids, the form being named after SocialSeriesType
                    'label' => 'label.guided_step_social_series_frequency',
                    'description' => 'description.guided_step_social_series_frequency',
                    'narration' => 'narration.guided_step_social_series_frequency',
                    'highlight' => '#social_series_frequency',
                ],
                [
                    'label' => 'label.guided_step_social_series_mode',
                    'description' => 'description.guided_step_social_series_mode',
                    'narration' => 'narration.guided_step_social_series_mode',
                    'highlight' => '#social_series_mode',
                ],
                [
                    // Required unless the site's contents write the posts, so walked through rather than left for the generation to refuse
                    'label' => 'label.guided_step_social_series_text',
                    'description' => 'help.social_series_text',
                    'narration' => 'narration.guided_step_social_series_text',
                    'highlight' => '#social_series_text',
                ],
                [
                    'label' => 'label.guided_step_social_series_media',
                    'description' => 'help.social_series_media',
                    'narration' => 'narration.guided_step_social_series_media',
                    'highlight' => '#social_series_media',
                ],
                [
                    'label' => 'label.guided_step_social_series_generate',
                    'description' => 'description.guided_step_social_series_generate',
                    'narration' => 'narration.guided_step_social_series_generate',
                    'highlight' => 'form[name="social_series"] button[type="submit"]',
                ],
            ],
        ];
    }

    // What the editor does with the posts: prepare them, read them, correct them, approve or send them - saving before sending, "Publish" being offered on the list alone
    private function socialPostsProject(): array
    {
        return [
            'slug' => 'social-posts',
            'label' => 'label.guided_project_social_posts',
            'description' => 'description.guided_project_social_posts',
            'translation_domain' => 'social',
            'order' => 4070,
            // The bar SocialPostCrudController states on its own rows
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_posts_open',
                    'description' => 'description.guided_step_social_posts_open',
                    'narration' => 'narration.guided_step_social_posts_open',
                    'url' => $this->indexUrl(SocialPostCrudController::class),
                ],
                [
                    // A series of drafts at a steady pace, from one text, the AI or the sources
                    'label' => 'label.guided_step_social_posts_series',
                    'description' => 'description.guided_step_social_posts_series',
                    'narration' => 'narration.guided_step_social_posts_series',
                    'highlight' => '.action-generateSeries',
                ],
                [
                    // The global action preparing a draft of any page by its address
                    'label' => 'label.guided_step_social_posts_prepare',
                    'description' => 'description.guided_step_social_posts_prepare',
                    'narration' => 'narration.guided_step_social_posts_prepare',
                    'highlight' => '.action-prepareUrlPost',
                ],
                [
                    'label' => 'label.guided_step_social_posts_edit',
                    'description' => 'description.guided_step_social_posts_edit',
                    'narration' => 'narration.guided_step_social_posts_edit',
                    'highlight' => '.action-edit',
                ],
                [
                    // The fields' own ids, EasyAdmin naming the form after the entity - each described by the help its screen shows
                    'label' => 'label.guided_step_social_posts_planned_at',
                    'description' => 'help.social_post_planned_at',
                    'narration' => 'narration.guided_step_social_posts_planned_at',
                    'highlight' => '#SocialPost_plannedAt',
                ],
                [
                    'label' => 'label.guided_step_social_posts_medias',
                    'description' => 'help.social_post_medias',
                    'narration' => 'narration.guided_step_social_posts_medias',
                    'highlight' => '#SocialPost_medias',
                ],
                [
                    'label' => 'label.guided_step_social_posts_networks',
                    'description' => 'help.social_post_send_on',
                    'narration' => 'narration.guided_step_social_posts_networks',
                    'highlight' => '#SocialPost_networks',
                ],
                [
                    'label' => 'label.guided_step_social_posts_save',
                    'narration' => 'narration.guided_step_social_posts_save',
                    'highlight' => '.action-saveAndReturn',
                ],
                [
                    // The way a post goes out at its moment, before "Publish" sending it at once
                    'label' => 'label.guided_step_social_posts_approve',
                    'description' => 'description.guided_step_social_posts_approve',
                    'narration' => 'narration.guided_step_social_posts_approve',
                    'highlight' => '.action-approvePost',
                ],
                [
                    // Rendered as an icon, it keeps its action-publishPost class - and matches nothing on a post every network already took
                    'label' => 'label.guided_step_social_posts_publish',
                    'description' => 'description.guided_step_social_posts_publish',
                    'narration' => 'narration.guided_step_social_posts_publish',
                    'highlight' => '.action-publishPost',
                ],
            ],
        ];
    }

    // Google's connection parcours again, for Meta: the app is created on developers.facebook.com, its two keys are restricted configs, then consenting fills the Page, its token and the Instagram account
    private function metaConnectProject(): array
    {
        return [
            'slug' => 'social-meta-connect',
            'label' => 'label.guided_project_social_meta_connect',
            'description' => 'description.guided_project_social_meta_connect',
            'translation_domain' => 'social',
            'order' => 4060,
            // The two keys are restricted configs, with the same three roles as Google's (see googleConnectProject())
            'role' => $this->restrictedConfigRoles(),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_meta_prerequisites',
                    'description' => 'description.guided_step_social_meta_prerequisites',
                    'narration' => 'narration.guided_step_social_meta_prerequisites',
                    'url' => $this->indexUrl(ConfigCrudController::class),
                ],
                [
                    'label' => 'label.guided_step_social_meta_credentials',
                    'description' => 'description.guided_step_social_meta_credentials',
                    'narration' => 'narration.guided_step_social_meta_credentials',
                ],
                $this->connectionsStep(),
                [
                    'label' => 'label.guided_step_social_meta_connect',
                    'description' => 'description.guided_step_social_meta_connect',
                    'narration' => 'narration.guided_step_social_meta_connect',
                    'highlight' => 'a[href*="/social/meta/connect"]',
                ],
            ],
        ];
    }

    // Meta's parcours without its first half: no app to create nor key to paste, the site being its own OAuth client (see BlueskyOAuthClient) - so it opens on the connections screen, the handle left to an optional last step
    private function blueskyConnectProject(): array
    {
        return [
            'slug' => 'social-bluesky-connect',
            'label' => 'label.guided_project_social_bluesky_connect',
            'description' => 'description.guided_project_social_bluesky_connect',
            'translation_domain' => 'social',
            'order' => 4080,
            // site-role-admin for the configs screen, site-role-editor for the connections screen - neither implying the other, both required
            'role' => [$this->configService->get('site-role-admin'), $this->configService->get('site-role-editor')],
            'steps' => [
                [
                    // The screen opened at once, a route of its own rather than a CRUD index
                    'label' => 'label.guided_step_social_connections_open',
                    'description' => 'description.guided_step_social_connections_open',
                    'narration' => 'narration.guided_step_social_connections_open',
                    'url' => $this->urlGenerator->generate(SocialConnectionsController::ROUTE),
                ],
                [
                    'label' => 'label.guided_step_social_bluesky_connect',
                    'description' => 'description.guided_step_social_bluesky_connect',
                    'narration' => 'narration.guided_step_social_bluesky_connect',
                    'highlight' => 'a[href*="/social/bluesky/connect"]',
                ],
                [
                    // No highlight: consenting leaves the site, and the handle is a config found with the screen's own search
                    'label' => 'label.guided_step_social_bluesky_handle',
                    'description' => 'description.guided_step_social_bluesky_handle',
                    'narration' => 'narration.guided_step_social_bluesky_handle',
                ],
            ],
        ];
    }

    // Meta's parcours again, for LinkedIn: the app is created on linkedin.com/developers, its two keys are restricted configs, then consenting fills the token and the member - for 60 days, LinkedIn handing a self-serve app no refresh token
    private function linkedinConnectProject(): array
    {
        return [
            'slug' => 'social-linkedin-connect',
            'label' => 'label.guided_project_social_linkedin_connect',
            'description' => 'description.guided_project_social_linkedin_connect',
            'translation_domain' => 'social',
            'order' => 4090,
            // The two keys are restricted configs, with the same three roles as Google's (see googleConnectProject())
            'role' => $this->restrictedConfigRoles(),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_linkedin_prerequisites',
                    'description' => 'description.guided_step_social_linkedin_prerequisites',
                    'narration' => 'narration.guided_step_social_linkedin_prerequisites',
                    'url' => $this->indexUrl(ConfigCrudController::class),
                ],
                [
                    'label' => 'label.guided_step_social_linkedin_credentials',
                    'description' => 'description.guided_step_social_linkedin_credentials',
                    'narration' => 'narration.guided_step_social_linkedin_credentials',
                ],
                $this->connectionsStep(),
                [
                    'label' => 'label.guided_step_social_linkedin_connect',
                    'description' => 'description.guided_step_social_linkedin_connect',
                    'narration' => 'narration.guided_step_social_linkedin_connect',
                    'highlight' => 'a[href*="/social/linkedin/connect"]',
                ],
            ],
        ];
    }

    // Every post at its moment, moved with a drag - the screen is a route of its own, not a CRUD index
    private function socialCalendarProject(): array
    {
        return [
            'slug' => 'social-calendar',
            'label' => 'label.guided_project_social_calendar',
            'description' => 'description.guided_project_social_calendar',
            'translation_domain' => 'social',
            'order' => 4100,
            // The bar SocialCalendarController states on its own actions
            'role' => $this->configService->get('site-role-editor'),
            'steps' => [
                [
                    'label' => 'label.guided_step_social_calendar_open',
                    'description' => 'description.guided_step_social_calendar_open',
                    'narration' => 'narration.guided_step_social_calendar_open',
                    'url' => $this->urlGenerator->generate(SocialCalendarController::ROUTE),
                ],
                [
                    // The button and the double click both open the screen of a new post, the double click planning it where it was made
                    'label' => 'label.guided_step_social_calendar_new',
                    'description' => 'description.guided_step_social_calendar_new',
                    'narration' => 'narration.guided_step_social_calendar_new',
                    'highlight' => '.social-calendar-new',
                ],
                [
                    // The coming quarters of an hour of the week, or days of the month, a card is dropped on
                    'label' => 'label.guided_step_social_calendar_move',
                    'description' => 'description.guided_step_social_calendar_move',
                    'narration' => 'narration.guided_step_social_calendar_move',
                    'highlight' => '[data-social-calendar-target="zone"]',
                ],
                [
                    // No highlight: the calendar may hold no post to point at
                    'label' => 'label.guided_step_social_calendar_panel',
                    'description' => 'description.guided_step_social_calendar_panel',
                    'narration' => 'narration.guided_step_social_calendar_panel',
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

    // Which networks the buttons offer, and how they look - the band switched off saying so on the screen itself
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
