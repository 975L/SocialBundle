<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use PHPUnit\Framework\TestCase;
use Twig\Environment;

// The LinkedIn tile of "Connexions" and "Choisir la Page LinkedIn" rendered for real, EasyAdmin's components included, as SocialCalendarTemplateTest renders the calendar
class SocialLinkedInTemplatesTest extends TestCase
{
    // The kernel's Twig (see TemplateTestKernel::twig())
    private function twig(): Environment
    {
        return TemplateTestKernel::twig();
    }

    // The LinkedIn tile, connected on the given accounts, beside a tile of one button
    /** @param list<array{icon: string, name: string, url: ?string}> $accounts */
    private function connections(array $accounts = []): string
    {
        return $this->twig()->render('@c975LSocial/management/social_connections.html.twig', ['networks' => [
            ['name' => 'linkedin', 'icon' => 'fab fa-linkedin', 'route' => 'social_linkedin_oauth_connect', 'configured' => true, 'connected' => true, 'accounts' => $accounts, 'expires_at' => new \DateTimeImmutable('+30 days'), 'page_button' => true],
            ['name' => 'youtube', 'icon' => 'fab fa-youtube', 'route' => 'social_youtube_oauth_connect', 'configured' => true, 'connected' => false, 'accounts' => []],
        ]]);
    }

    // The profile and the Page each their button on the same route, the other tiles keeping their single one
    public function testTheLinkedInTileOffersAProfileAndAPage(): void
    {
        $html = $this->connections();

        $this->assertStringContainsString('href="/social_linkedin_oauth_connect"', $html);
        $this->assertStringContainsString('href="/social_linkedin_oauth_connect?page=1"', $html);
        $this->assertStringContainsString('label.connection_connect_profile', $html);
        $this->assertStringContainsString('label.connection_connect_page', $html);
        $this->assertStringContainsString('href="/social_youtube_oauth_connect"', $html);
        $this->assertStringContainsString('label.connection_connect<', str_replace(["\n", ' '], '', $html));
    }

    // The Page connected shown under its name, opened on LinkedIn
    public function testTheLinkedInTileLinksThePageConnected(): void
    {
        $html = $this->connections([['icon' => 'fab fa-linkedin', 'name' => '975L', 'url' => 'https://www.linkedin.com/company/111/']]);

        $this->assertStringContainsString('<a href="https://www.linkedin.com/company/111/" target="_blank" rel="noopener noreferrer">975L</a>', $html);
    }

    // One card per Page, each its button, the current one flagged
    public function testThePickerOffersEachPageAndFlagsTheCurrentOne(): void
    {
        $html = $this->twig()->render('@c975LSocial/management/social_linkedin_page.html.twig', [
            'organizations' => [['id' => '111', 'name' => '975L'], ['id' => '222', 'name' => 'EIPT']],
            'current' => '222',
            'token' => 'social_linkedin_page',
        ]);

        $this->assertStringContainsString('value="token-social_linkedin_page"', $html);
        $this->assertStringContainsString('href="https://www.linkedin.com/company/111/"', $html);
        $this->assertMatchesRegularExpression('/name="organization"[^>]*value="222"|value="222"[^>]*name="organization"/', $html);
        $this->assertSame(1, substr_count($html, 'label.social_linkedin_page_current'));
        $this->assertSame(2, substr_count($html, 'label.social_linkedin_page_pick'));
    }
}
