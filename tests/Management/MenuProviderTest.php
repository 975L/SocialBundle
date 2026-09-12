<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Management;

use c975L\ConfigBundle\Repository\ConfigRepository;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\SocialBundle\Controller\Management\ShareButtonsSettingsCrudController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Management\MenuProvider;
use c975L\UiBundle\Service\ConfigEditUrlResolver;
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
            'site-role-admin' => 'ROLE_ADMIN',
            default => '0',
        });
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        $configEditUrlResolver = $this->createStub(ConfigEditUrlResolver::class);
        $configEditUrlResolver->method('resolve')->willReturn('/management/config/12/edit');

        return new MenuProvider($configService, $this->createStub(ConfigRepository::class), $configEditUrlResolver);
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
            ['label' => 'label.social', 'translation_domain' => 'social', 'icon' => 'fas fa-share-nodes'],
            $provider->getMenuSection()
        );
    }

    // Share buttons settings stay out of the section while the feature is disabled site-wide, the link standing in for them saying so instead (see testGetLinksStandsInForTheSettingsScreenWhenShareButtonsAreDisabled). The reviews screen is not here at all any more: the entity and its moderation moved to UiBundle, which declares its own entry
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

    // Off, the section used to say nothing at all about a feature the bundle ships: the entry stays and walks to the switch itself, drawn among this bundle's entries (no target) where the settings screen sits once it reads true
    public function testGetLinksStandsInForTheSettingsScreenWhenShareButtonsAreDisabled(): void
    {
        $links = $this->createProvider(false)->getLinks();

        $this->assertArrayHasKey('social_share_buttons_disabled', $links);
        $this->assertSame('/management/config/12/edit', $links['social_share_buttons_disabled']['url']);
        $this->assertArrayNotHasKey('target', $links['social_share_buttons_disabled']);
    }

    // The switch lives on ConfigCrudController, which denies anything below the admin bar - an editor sent there would only meet a 403, and the tour would walk them to it
    public function testTheDisabledShareButtonsLinkNamesTheAdminBarItsScreenStates(): void
    {
        $links = $this->createProvider(false)->getLinks();

        $this->assertSame('ROLE_ADMIN', $links['social_share_buttons_disabled']['role']);
    }

    // Flipped on, the settings screen is reachable again and the stand-in has nothing left to say
    public function testTheDisabledShareButtonsLinkIsDroppedOnceTheyAreEnabled(): void
    {
        $this->assertArrayNotHasKey('social_share_buttons_disabled', $this->createProvider(true)->getLinks());
    }

    // Reviews off no longer empties the whole list: the stand-in belongs to another switch entirely
    public function testTheGoogleConnectionLeavesTheDisabledShareButtonsLinkBehind(): void
    {
        $this->assertSame(['social_share_buttons_disabled'], array_keys($this->createProvider(false, false)->getLinks()));
    }

    // Every link gets a step in the onboarding tour too, and an untranslated description reads as its own key
    public function testEveryLinkCarriesATranslatedDescription(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider(false)->getLinks() as $slug => $link) {
            $this->assertArrayHasKey('description', $link, sprintf('Link "%s" says nothing about where it goes', $slug));
            $this->assertContains($link['description'], $translated);
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
