<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // Builds a provider whose ConfigService answers each feature switch on its own: the two are independent, and a test setting both at once could not tell which entry follows which
    private function createProvider(bool $shareButtonsEnabled, bool $reviewsEnabled = true): MenuProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'social-enable-share-buttons' => $shareButtonsEnabled ? '1' : '0',
            'ui-enable-reviews' => $reviewsEnabled ? '1' : '0',
            'site-role-editor' => 'ROLE_EDITOR',
            default => '0',
        });
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        return new MenuProvider($configService);
    }

    // Its two screens are both the editor's: without the key an entry takes the admin default and goes missing from their sidebar, with the tour step that walks to it (see MenuProviderInterface::getMenus())
    public function testEveryEntryNamesTheEditorBarItsOwnScreenStates(): void
    {
        $menus = $this->createProvider(true)->getMenus();

        foreach (['social_links', 'share_buttons_settings'] as $slug) {
            $this->assertSame('ROLE_EDITOR', $menus[$slug]['role'], sprintf('The "%s" entry does not name the bar its own crud states', $slug));
        }
    }

    // The dashboard groups this bundle's menus under a fixed "social" section
    public function testGetMenuSectionReturnsSocialLabelAndTranslationDomain(): void
    {
        $provider = $this->createProvider(false);

        $this->assertSame(
            ['label' => 'label.social', 'translation_domain' => 'social'],
            $provider->getMenuSection()
        );
    }

    // Share buttons settings must stay hidden while the feature is disabled site-wide. The reviews screen is not here at all any more: the entity and its moderation moved to UiBundle, which declares its own entry
    public function testGetMenusOnlyIncludesSocialLinksWhenShareButtonsDisabled(): void
    {
        $provider = $this->createProvider(false);

        $menus = $provider->getMenus();

        $this->assertSame(['social_links'], array_keys($menus));
        $this->assertSame(SocialLinksCrudController::class, $menus['social_links']['controller']);
    }

    // Enabling "social-enable-share-buttons" exposes its own settings entry, after the unconditional ones
    public function testGetMenusIncludesShareButtonsSettingsWhenEnabled(): void
    {
        $provider = $this->createProvider(true);

        $menus = $provider->getMenus();

        $this->assertSame(['social_links', 'share_buttons_settings'], array_keys($menus));
        $this->assertSame(ShareButtonsSettingsCrudController::class, $menus['share_buttons_settings']['controller']);
    }

    // Connecting Google is what fetches the reviews, so the link goes with them
    public function testTheGoogleConnectionIsDroppedWithTheReviews(): void
    {
        $this->assertSame([], $this->createProvider(true, false)->getLinks());
    }

    // The Google connection is a route, not a Crud screen, so it is a link - tiered "advanced", being run once when the site is first connected
    public function testGetLinksOffersTheGoogleConnectionInTheAdvancedTier(): void
    {
        $links = $this->createProvider(true)->getLinks();

        $this->assertSame(['social_google_connect'], array_keys($links));
        $this->assertSame('social_google_oauth_connect', $links['social_google_connect']['name']);
        $this->assertSame('advanced', $links['social_google_connect']['tier']);
    }

    // Every entry gets a step in the onboarding tour, one without a description showing its label alone - and an untranslated one reads as its own key
    public function testEveryMenuCarriesATranslatedDescription(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider(true)->getMenus() as $slug => $menu) {
            $this->assertArrayHasKey('description', $menu, sprintf('Menu "%s" says nothing about the screen it opens', $slug));
            $this->assertContains($menu['description'], $translated);
        }
    }

    private function translatedKeys(): array
    {
        $xliff = new \DOMDocument();
        $xliff->load(\dirname(__DIR__, 2) . '/translations/social.fr.xlf');

        $keys = [];
        foreach ($xliff->getElementsByTagName('source') as $source) {
            $keys[] = $source->textContent;
        }

        return $keys;
    }
}
