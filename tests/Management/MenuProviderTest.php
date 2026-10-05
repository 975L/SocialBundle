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
use c975L\SocialBundle\Controller\Management\SocialCalendarController;
use c975L\SocialBundle\Controller\Management\SocialConnectionsController;
use c975L\SocialBundle\Controller\Management\SocialLinksCrudController;
use c975L\SocialBundle\Controller\Management\SocialPostCrudController;
use c975L\SocialBundle\Management\MenuProvider;
use PHPUnit\Framework\TestCase;

class MenuProviderTest extends TestCase
{
    // No switch is read any more: every entry is listed whatever the site uses
    private function createProvider(): MenuProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnCallback(static fn (string $slug): string => match ($slug) {
            'site-role-editor' => 'ROLE_EDITOR',
            default => '0',
        });

        return new MenuProvider($configService);
    }

    // Every screen is the editor's: without the key an entry takes the admin default and goes missing from their sidebar, with the tour step that walks to it (see MenuProviderInterface::getMenus())
    public function testEveryEntryNamesTheEditorBarItsOwnScreenStates(): void
    {
        foreach ($this->createProvider()->getMenus() as $slug => $menu) {
            $this->assertSame('ROLE_EDITOR', $menu['role'], sprintf('The "%s" entry does not name the bar its own crud states', $slug));
        }
    }

    // The dashboard groups this bundle's menus under a fixed "social" section
    public function testGetMenuSectionReturnsSocialLabelAndTranslationDomain(): void
    {
        $this->assertSame(
            ['label' => 'label.social', 'translation_domain' => 'social', 'icon' => 'fas fa-share-nodes'],
            $this->createProvider()->getMenuSection()
        );
    }

    // Nothing hidden: a screen governing nothing yet says so itself (share buttons off, no network connected)
    public function testGetMenusListsEveryScreenWhateverTheSiteUses(): void
    {
        $menus = $this->createProvider()->getMenus();

        $this->assertSame(['social_links', 'share_buttons_settings', 'social_posts'], array_keys($menus));
        $this->assertSame(SocialLinksCrudController::class, $menus['social_links']['controller']);
        $this->assertSame(ShareButtonsSettingsCrudController::class, $menus['share_buttons_settings']['controller']);
        $this->assertSame(SocialPostCrudController::class, $menus['social_posts']['controller']);
    }

    // One entry for every network, the connections screen drawing a tile for each, and the calendar - two screens, no entity behind either
    public function testGetLinksOffersTheCalendarAndTheConnectionsScreen(): void
    {
        $links = $this->createProvider()->getLinks();

        $this->assertSame(['social_calendar', 'social_connections'], array_keys($links));
        $this->assertSame(SocialCalendarController::ROUTE, $links['social_calendar']['name']);
        $this->assertSame('ROLE_EDITOR', $links['social_calendar']['role']);
        $this->assertSame(SocialConnectionsController::ROUTE, $links['social_connections']['name']);
        $this->assertSame('ROLE_EDITOR', $links['social_connections']['role']);
        $this->assertArrayNotHasKey('tier', $links['social_connections']);
    }

    // Every entry gets a step in the onboarding tour, one without a description showing its label alone - and an untranslated one reads as its own key
    public function testEveryMenuCarriesATranslatedDescription(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider()->getMenus() as $slug => $menu) {
            $this->assertArrayHasKey('description', $menu, sprintf('Menu "%s" says nothing about the screen it opens', $slug));
            $this->assertContains($menu['description'], $translated);
        }
    }

    // Every link gets a step in the onboarding tour too, and an untranslated description reads as its own key
    public function testEveryLinkCarriesATranslatedDescription(): void
    {
        $translated = $this->translatedKeys();

        foreach ($this->createProvider()->getLinks() as $slug => $link) {
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
