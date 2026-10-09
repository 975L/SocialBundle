<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

// The calendar rendered for real: a filter or a macro call that does not exist only shows once the template runs, which a test reading its source would never see
class SocialCalendarTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        $files = new FilesystemLoader();
        $files->addPath(\dirname(__DIR__, 2) . '/templates', 'c975LSocial');
        $twig = new Environment(new ChainLoader([new ArrayLoader(['@EasyAdmin/page/content.html.twig' => '{% block main %}{% endblock %}']), $files]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/' . $route . ([] === $parameters ? '' : '?' . http_build_query($parameters))));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $id): string => 'token-' . $id));
        $twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/' . $path));
        $twig->addFunction(new TwigFunction('social_link_icon', static fn (string $network): ?string => 'linkedin' === $network ? null : 'bundles/c975lsocial/icons/' . $network . '.svg'));
        $twig->addFilter(new TwigFilter('trans', static fn (mixed $key): string => \is_string($key) ? $key : 'translated'));

        return $twig;
    }

    private function render(string $view = 'month', ?string $feedUrl = null): string
    {
        $twig = $this->twig();

        $card = static fn (int $id, string $state, \DateTimeImmutable $at): array => ['id' => $id, 'title' => 'La sieste', 'image' => null, 'networks' => ['bluesky'], 'planned_at' => $at, 'state' => $state, 'edit_url' => '/edit/' . $id];
        $evening = new \DateTimeImmutable('2026-10-04 19:00');
        $planned = new \DateTimeImmutable('2026-10-04 14:15');
        $items = [
            ['kind' => 'planned', 'at' => $planned] + $card(8, 'approved', $planned),
            ['kind' => 'planned', 'at' => $evening] + $card(9, 'draft', $evening),
            ['kind' => 'planned', 'at' => $evening] + $card(10, 'failed', $evening),
        ];
        $published = [['kind' => 'published', 'at' => $evening->modify('-1 day')] + ['networks' => ['facebook']] + $card(7, 'published', $evening->modify('-2 days'))];
        // The week's two quarters of an hour around the planned post, the first one gone by
        $cells = [['at' => $planned->modify('-15 minutes'), 'open' => false], ['at' => $planned, 'open' => true]];
        $day = static fn (string $date, bool $open, array $dayItems, array $rows): array => ['date' => new \DateTimeImmutable($date), 'open' => $open, 'in_month' => true, 'today' => false, 'items' => $dayItems, 'cells' => 'week' === $view ? $cells : [], 'rows' => 'week' === $view ? $rows : []];

        return $twig->render('@c975LSocial/management/social_calendar.html.twig', [
            'view' => $view,
            'title' => '28 sept. - 4 oct. 2026',
            'date' => '2026-09-28',
            'hours' => 'week' === $view ? ['06:00', '07:00'] : [],
            'networks' => [['name' => 'bluesky', 'connected' => true], ['name' => 'linkedin', 'connected' => false]],
            'connected' => ['bluesky'],
            'connections_route' => 'management_social_connections',
            'weekdays' => ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'],
            'weeks' => [[
                $day('2026-10-03', false, $published, [['row' => 52, 'lane' => 0, 'lanes' => 1, 'items' => $published]]),
                $day('2026-10-04', true, $items, [['row' => 33, 'lane' => 0, 'lanes' => 2, 'items' => [$items[0]]], ['row' => 52, 'lane' => 1, 'lanes' => 2, 'items' => [$items[1], $items[2]]]]),
            ]],
            'previous' => '2026-09-21',
            'next' => '2026-10-05',
            'move_url' => '/move',
            'panel_url' => '/panel',
            'new_url' => '/new',
            'series_url' => '/series',
            'token' => 'social_calendar',
            'feed_url' => $feedUrl,
            'feed_token_url' => '/management/social-calendar/feed',
        ]);
    }

    // The panel of a post in the given state, its approval being what the state offers
    private function panel(string $state): string
    {
        $post = new SocialPost('gallery_media', '42', 'La sieste', 'https://example.org/42', null, new \DateTimeImmutable('2026-10-04 19:00'));
        new SocialPostTarget($post, 'bluesky', 'Le texte de Bluesky');

        return $this->twig()->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => ['id' => 7, 'title' => 'La sieste', 'image' => null, 'networks' => ['bluesky'], 'planned_at' => $post->getPlannedAt(), 'state' => $state, 'edit_url' => '/edit/7'],
            'state' => $state,
            'targets' => $post->getTargets(),
            'connected' => ['bluesky'],
        ]);
    }

    // The week: every coming quarter of an hour a drop zone at its moment, one gone by none, and each item laid at its quarter
    public function testTheWeekIsAGridOfQuartersOfAnHour(): void
    {
        $html = $this->render('week');

        $this->assertSame(2, substr_count($html, 'data-to="' . new \DateTimeImmutable('2026-10-04 14:15')->format('c') . '"'));
        $this->assertStringContainsString('social-week-cell is-past', $html);
        $this->assertStringContainsString('data-row="33" data-lane="0" data-lanes="2"', $html);
        $this->assertStringContainsString('data-row="52" data-lane="1" data-lanes="2"', $html);
        $this->assertStringContainsString('data-at="' . new \DateTimeImmutable('2026-10-04 14:15')->format('c') . '"', $html);
        $this->assertStringContainsString('class="social-week-hour">06:00', $html);
    }

    // The month: a day from today on is a drop zone at its date alone, the post keeping its time there; a day gone by takes nothing
    public function testEveryOpenDayOfTheMonthIsADropZoneAtItsDate(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-social-calendar-target="zone" data-to="2026-10-04"', $html);
        $this->assertStringNotContainsString('data-to="2026-10-03"', $html);
    }

    // A coming moment double-clicked writes a post there, the button writing one at the next quarter of an hour; a moment gone by offers neither
    public function testAComingMomentIsDoubleClickedToWriteAPost(): void
    {
        $week = $this->render('week');
        $month = $this->render();

        $this->assertStringContainsString('data-to="' . new \DateTimeImmutable('2026-10-04 14:15')->format('c') . '" data-action="dblclick->social-calendar#create"', $week);
        $this->assertStringContainsString('data-to="2026-10-04" data-action="dblclick->social-calendar#create"', $month);
        $this->assertSame(1, substr_count($month, 'dblclick->social-calendar#create'));
        $this->assertStringContainsString('class="btn btn-primary social-calendar-new" href="/new"', $month);
        $this->assertStringContainsString('data-social-calendar-new-url-value="/new"', $month);
    }

    // Each card is coloured by its state, and only one gone out everywhere does not drag
    public function testCardsShowTheirStateAndOnlyWaitingOnesDrag(): void
    {
        $html = $this->render();

        foreach (['published', 'approved', 'draft', 'failed'] as $state) {
            $this->assertStringContainsString('class="social-calendar-card is-' . $state . '"', $html);
        }
        $this->assertSame(3, substr_count($html, 'data-social-calendar-target="item"'));
        $this->assertStringNotContainsString('data-post-id="7"', $html);
        $this->assertStringContainsString('data-post-id="9" data-at="' . new \DateTimeImmutable('2026-10-04 19:00')->format('c') . '"', $html);
    }

    // Each network shows as its own glyph on its colour, greyed while the site is not connected to it - one without a glyph keeps its initial
    public function testTheNetworksShowTheirIconAndWhetherTheyAreConnected(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<img src="/bundles/c975lsocial/icons/bluesky.svg" alt="">', $html);
        $this->assertStringContainsString('social-network-badge social-network-badge--linkedin is-off', $html);
        $this->assertStringContainsString('/management_social_connections', $html);
    }

    // The panel of a draft: its state, the field set to its moment, the approval offered, its texts, and the post's screen a link away
    public function testThePanelOfADraftLetsItBePlannedAndApproved(): void
    {
        $html = $this->panel('draft');

        $this->assertStringContainsString('label.social_calendar_state_draft', $html);
        $this->assertStringContainsString('value="2026-10-04T19:00"', $html);
        $this->assertStringContainsString('data-action="social-calendar#plan" data-post-id="7"', $html);
        $this->assertStringContainsString('data-to="approve"', $html);
        $this->assertStringNotContainsString('data-to="unapprove"', $html);
        $this->assertStringContainsString('Le texte de Bluesky', $html);
        $this->assertStringContainsString('href="/edit/7"', $html);
    }

    // The panel of an approved post offers to take it back to a draft instead
    public function testThePanelOfAnApprovedPostOffersItsWithdrawal(): void
    {
        $html = $this->panel('approved');

        $this->assertStringContainsString('label.social_calendar_state_approved', $html);
        $this->assertStringContainsString('data-to="unapprove"', $html);
        $this->assertStringNotContainsString('data-to="approve"', $html);
    }

    // A post gone out everywhere is only read: no moment to set, no approval to change
    public function testThePanelOfAPublishedPostOnlyShowsIt(): void
    {
        $html = $this->panel('published');

        $this->assertStringContainsString('label.social_calendar_state_published', $html);
        $this->assertStringNotContainsString('social-calendar#plan', $html);
        $this->assertStringNotContainsString('data-to=', $html);
        $this->assertStringContainsString('Le texte de Bluesky', $html);
    }

    // Its secret address offered to copy once created, a button creating it otherwise - renewing it once it exists
    public function testTheFeedAddressIsOfferedOnceCreated(): void
    {
        $none = $this->render('week');
        $this->assertStringContainsString('label.social_calendar_feed_create', $none);
        $this->assertStringNotContainsString('.ics', $none);

        $created = $this->render('week', 'https://example.org/social/calendar/' . str_repeat('a', 64) . '.ics');
        $this->assertStringContainsString('value="https://example.org/social/calendar/' . str_repeat('a', 64) . '.ics" readonly', $created);
        $this->assertStringContainsString('label.social_calendar_feed_renew', $created);
    }
}
