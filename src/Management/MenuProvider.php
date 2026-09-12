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
use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\UiBundle\Service\ConfigEditUrlResolver;

class MenuProvider implements MenuProviderInterface
{
    // The site-wide switch the share buttons band is drawn under (see ShareButtonsExtension and default.html.twig)
    private const string SHARE_BUTTONS_SLUG = 'social-enable-share-buttons';

    // The switch's edit url, resolved on the first getLinks() needing it (see shareButtonsSwitchUrl())
    private ?string $shareButtonsSwitchUrl = null;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly ConfigRepository $configRepository,
        private readonly ConfigEditUrlResolver $configEditUrlResolver,
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

    public function getMenus(): array
    {
        $menus = [
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
        ];

        // Settings for a band the site does not draw would govern nothing: turned off, the entry is a link to the switch instead (see getLinks())
        if ($this->shareButtonsEnabled()) {
            $menus['share_buttons_settings'] = [
                'controller' => ShareButtonsSettingsCrudController::class,
                'label' => 'label.share_buttons_settings',
                'narration' => 'narration.share_buttons_settings',
                'translation_domain' => 'social',
                'icon' => 'fas fa-share-nodes',
                'description' => 'label.info_share_buttons_settings',
                // The bar ShareButtonsSettingsCrudController states on its own rows
                'role' => $this->configService->get('site-role-editor'),
            ];
        }

        return $menus;
    }

    public function getLinks(): array
    {
        $links = [];

        // Turned off, the entry stays as a link (no target, so drawn among this bundle's entries) walking to its own switch. Admin rather than editor: ConfigCrudController denies anything below it, so an editor sent there, or walked there by the tour, would only meet a 403
        if (!$this->shareButtonsEnabled()) {
            $links['social_share_buttons_disabled'] = [
                'url' => $this->shareButtonsSwitchUrl(),
                'label' => 'label.share_buttons_disabled',
                'narration' => 'narration.share_buttons_disabled',
                'translation_domain' => 'social',
                'icon' => 'fas fa-toggle-off',
                'role' => $this->configService->get('site-role-admin'),
                // Written for the tour alone, where the other descriptions quote their screen: this one stands in for a screen that is not reachable yet
                'description' => 'label.info_share_buttons_disabled',
            ];
        }

        // A route redirecting straight to Google's consent page, tiered "advanced" as it is run once and again the day the token is revoked. Dropped with the reviews off, as it would fetch reviews the site never shows
        if ($this->configService->getBool($this->configService->get('ui-enable-reviews'))) {
            $links['social_google_connect'] = [
                'name' => 'social_google_oauth_connect',
                'label' => 'label.google_connect',
                'narration' => 'narration.google_connect',
                'translation_domain' => 'social',
                'icon' => 'fab fa-google',
                'role' => $this->configService->get('site-role-editor'),
                'tier' => 'advanced',
                // Written for the tour alone, where the other descriptions quote their screen: a redirection has no screen whose text to reuse
                'description' => 'label.info_google_connect',
            ];
        }

        return $links;
    }

    // Read by both the settings entry and the link standing in for it, which are the two faces of the same switch
    private function shareButtonsEnabled(): bool
    {
        return $this->configService->getBool($this->configService->get(self::SHARE_BUTTONS_SLUG));
    }

    // Kept once resolved: the dashboard reads getLinks() several times per page, and findOneBySlug() queries the database on every call
    private function shareButtonsSwitchUrl(): string
    {
        return $this->shareButtonsSwitchUrl ??= $this->configEditUrlResolver->resolve($this->configRepository->findOneBySlug(self::SHARE_BUTTONS_SLUG));
    }
}
