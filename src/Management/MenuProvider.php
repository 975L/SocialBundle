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
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;

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
        ];
    }

    public function getMenus(): array
    {
        $menus = [
            'social_links' => [
                'controller' => SocialLinksCrudController::class,
                'label' => 'label.social_links',
                'translation_domain' => 'social',
                'icon' => 'fas fa-share-alt',
                // Same key as the screen's own explanatory text (see its crud/index and crud/edit overrides) - one text, reused, not a separate onboarding-only string (see MenuProviderInterface::getMenus())
                'description' => 'label.info_social_links',
            ],
        ];

        // Only displayed if share buttons are enabled site-wide (see "social-enable-share-buttons" in ShareButtonsExtension)
        if ($this->configService->getBool($this->configService->get('social-enable-share-buttons'))) {
            $menus['share_buttons_settings'] = [
                'controller' => ShareButtonsSettingsCrudController::class,
                'label' => 'label.share_buttons_settings',
                'translation_domain' => 'social',
                'icon' => 'fas fa-share-nodes',
                'description' => 'label.info_share_buttons_settings',
            ];
        }

        return $menus;
    }

    public function getLinks(): array
    {
        return [];
    }
}
