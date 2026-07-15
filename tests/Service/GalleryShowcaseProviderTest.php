<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Service;

use c975L\SocialBundle\Service\GalleryShowcaseProvider;
use c975L\SocialBundle\Service\ShareButtonsServiceInterface;
use c975L\UiBundle\Service\IconServiceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

class GalleryShowcaseProviderTest extends TestCase
{
    private function createProvider(): GalleryShowcaseProvider
    {
        $twig = $this->createStub(Environment::class);
        $twig->method('render')->willReturnCallback(
            static fn (string $template, array $context) => "<!-- {$template} -->"
        );

        $shareButtonsService = $this->createStub(ShareButtonsServiceInterface::class);
        $shareButtonsService->method('getStyles')->willReturn(['distinct', 'ellipse', 'circle']);

        $iconService = $this->createStub(IconServiceInterface::class);
        $iconService->method('getIcons')->willReturn(['facebook' => 'icons/facebook.svg']);

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(static fn (string $id) => $id);

        return new GalleryShowcaseProvider($twig, $shareButtonsService, $iconService, $translator);
    }

    public function testGetShowcasesReturnsSocialLinksAndShareButtonsSections(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame(
            ['label.gallery_showcase_social_links', 'label.gallery_showcase_share_buttons'],
            array_keys($showcases)
        );
    }

    // One variant per SocialLinksType::ICON_STYLES choice
    public function testSocialLinksShowcaseCoversEveryIconStyle(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame(
            ['Minimal', 'Colored', 'Outline'],
            array_keys($showcases['label.gallery_showcase_social_links']['variants'])
        );
    }

    // Stands in for "social_links_display" - the gallery suppresses that kind's own regular preview
    // card once "kind" is set here, so it only shows up once
    public function testSocialLinksShowcaseStandsInForSocialLinksDisplay(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame('social_links_display', $showcases['label.gallery_showcase_social_links']['kind']);
    }

    // Stands in for "share_buttons_display" - the gallery suppresses that kind's own regular preview
    // card once "kind" is set here, so it only shows up once
    public function testShareButtonsShowcaseStandsInForShareButtonsDisplay(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame('share_buttons_display', $showcases['label.gallery_showcase_share_buttons']['kind']);
    }

    // One variant per ShareButtonsServiceInterface::getStyles() choice
    public function testShareButtonsShowcaseCoversEveryStyleReturnedByTheService(): void
    {
        $showcases = $this->createProvider()->getShowcases();

        $this->assertSame(
            ['Distinct', 'Ellipse', 'Circle'],
            array_keys($showcases['label.gallery_showcase_share_buttons']['variants'])
        );
    }
}
