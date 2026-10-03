<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Management;

use c975L\ConfigBundle\Management\MenuProviderInterface;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Controller\Management\SocialPostCrudController;
use c975L\SocialBundle\Controller\Management\SocialScheduleCrudController;

class MenuProvider implements MenuProviderInterface
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
    ) {
    }

    public function getMenuSection(): array
    {
        return [
            'label' => 'label.social',
            'translation_domain' => 'social',
            'icon' => 'fas fa-share-nodes',
        ];
    }

    // Every screen listed whatever the site uses: one that governs nothing yet says so itself, rather than going missing from the sidebar
    public function getMenus(): array
    {
        return [
            'social_links' => [
                'controller' => SocialLinksCrudController::class,
                'label' => 'label.social_links',
                'narration' => 'narration.social_links',
                'translation_domain' => 'social',
                'icon' => 'fas fa-share-alt',
                // Same key as the screen's own explanatory text (see its crud/index and crud/edit overrides) - one text, reused, not a separate onboarding-only string (see MenuProviderInterface::getMenus())
                'description' => 'label.info_social_links',
                // The bar SocialLinksCrudController states on its own rows
                'role' => $this->configService->get('site-role-editor'),
            ],
            'share_buttons_settings' => [
                'controller' => ShareButtonsSettingsCrudController::class,
                'label' => 'label.share_buttons_settings',
                'narration' => 'narration.share_buttons_settings',
                'translation_domain' => 'social',
                'icon' => 'fas fa-share-nodes',
                'description' => 'label.info_share_buttons_settings',
                // The bar ShareButtonsSettingsCrudController states on its own rows
                'role' => $this->configService->get('site-role-editor'),
            ],
            'social_posts' => [
                'controller' => SocialPostCrudController::class,
                // Lists what happened rather than what an admin makes: empty, it is no feature left unused (see UnusedFeatureBuilder)
                'creatable' => false,
                'label' => 'label.social_posts',
                'narration' => 'narration.social_posts',
                'translation_domain' => 'social',
                'icon' => 'fas fa-paper-plane',
                'description' => 'label.info_social_posts',
                // The bar SocialPostCrudController states on its own rows
                'role' => $this->configService->get('site-role-editor'),
            ],
            'social_schedules' => [
                'controller' => SocialScheduleCrudController::class,
                'label' => 'label.social_schedules',
                'narration' => 'narration.social_schedules',
                'translation_domain' => 'social',
                'icon' => 'fas fa-clock',
                'description' => 'label.info_social_schedules',
                // The bar SocialScheduleCrudController states on its own rows
                'role' => $this->configService->get('site-role-editor'),
            ],
        ];
    }

    // The networks the site connects to, gathered on one screen of tiles rather than one link each; the calendar, a screen of its own rather than a list of entities
    public function getLinks(): array
    {
        return [
            'social_calendar' => [
                'name' => SocialCalendarController::ROUTE,
                'label' => 'label.social_calendar',
                'narration' => 'narration.social_calendar',
                'translation_domain' => 'social',
                'icon' => 'fas fa-calendar-days',
                'role' => $this->configService->get('site-role-editor'),
                'description' => 'label.info_social_calendar',
            ],
            'social_connections' => [
                'name' => SocialConnectionsController::ROUTE,
                'label' => 'label.social_connections',
                'narration' => 'narration.social_connections',
                'translation_domain' => 'social',
                'icon' => 'fas fa-plug',
                'role' => $this->configService->get('site-role-editor'),
                'description' => 'label.info_social_connections',
            ],
        ];
    }
}
