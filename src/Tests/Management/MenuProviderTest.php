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

// Lives under src/Tests (not a sibling tests/ dir) so it stays autoloadable by consuming apps,
// whose attribute route loader recursively reflects every class under the bundle root
class MenuProviderTest extends TestCase
{
    // Builds a provider whose ConfigService answers "social-enable-share-buttons" with the given value
    private function createProvider(bool $shareButtonsEnabled): MenuProvider
    {
        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn($shareButtonsEnabled ? '1' : '0');
        $configService->method('getBool')->willReturnCallback(static fn ($value) => '1' === $value);

        return new MenuProvider($configService);
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

    // Share buttons settings must stay hidden while the feature is disabled site-wide
    public function testGetMenusOnlyIncludesSocialLinksWhenShareButtonsDisabled(): void
    {
        $provider = $this->createProvider(false);

        $menus = $provider->getMenus();

        $this->assertSame(['social_links'], array_keys($menus));
        $this->assertSame(SocialLinksCrudController::class, $menus['social_links']['controller']);
    }

    // Enabling "social-enable-share-buttons" exposes its own settings entry, after social_links
    public function testGetMenusIncludesShareButtonsSettingsWhenEnabled(): void
    {
        $provider = $this->createProvider(true);

        $menus = $provider->getMenus();

        $this->assertSame(['social_links', 'share_buttons_settings'], array_keys($menus));
        $this->assertSame(ShareButtonsSettingsCrudController::class, $menus['share_buttons_settings']['controller']);
    }

    // This bundle contributes no standalone dashboard links, only Crud menus
    public function testGetLinksReturnsEmptyArray(): void
    {
        $provider = $this->createProvider(true);

        $this->assertSame([], $provider->getLinks());
    }
}
