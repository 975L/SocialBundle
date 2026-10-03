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
use c975L\SocialBundle\Entity\SocialSchedule;
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

    private function render(string $view = 'month'): string
    {
        $twig = $this->twig();

        $post = ['id' => 7, 'title' => 'La sieste', 'image' => null, 'networks' => ['bluesky'], 'planned_at' => null, 'edit_url' => '/edit/7'];
        $at = new \DateTimeImmutable('2026-10-04 19:00');
        $planned = new \DateTimeImmutable('2026-10-04 14:15');
        $items = [
            ['kind' => 'planned', 'at' => $planned, 'slot' => null, 'slot_networks' => [], 'post' => ['sent' => ['bluesky'], 'pinned' => true, 'draft' => false] + ['id' => 8] + $post],
            ['kind' => 'slot', 'at' => $at, 'slot' => new SocialSchedule()->setName('Soir'), 'slot_networks' => ['bluesky'], 'post' => ['sent' => ['bluesky'], 'pinned' => false, 'draft' => false] + $post],
            ['kind' => 'slot', 'at' => $at->modify('+1 day'), 'slot' => new SocialSchedule()->setName('Soir'), 'slot_networks' => ['bluesky'], 'post' => null],
        ];
        $published = [['kind' => 'published', 'at' => $at->modify('-1 day'), 'networks' => ['facebook']] + $post];
        // The week's two quarters of an hour around the planned post, the first one gone by
        $cells = [['at' => $planned->modify('-15 minutes'), 'open' => false], ['at' => $planned, 'open' => true]];
        $day = static fn (string $date, array $dayItems, array $rows): array => ['date' => new \DateTimeImmutable($date), 'in_month' => true, 'today' => false, 'items' => $dayItems, 'cells' => 'week' === $view ? $cells : [], 'rows' => 'week' === $view ? $rows : []];

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
                $day('2026-10-03', $published, [['row' => 52, 'lane' => 0, 'lanes' => 1, 'items' => $published]]),
                $day('2026-10-04', $items, [['row' => 33, 'lane' => 0, 'lanes' => 2, 'items' => [$items[0]]], ['row' => 34, 'lane' => 1, 'lanes' => 2, 'items' => [$items[1]]]]),
            ]],
            'drafts' => [$post],
            'queue' => [],
            'has_slot' => false,
            'previous' => '2026-09-21',
            'next' => '2026-10-05',
            'move_url' => '/move',
            'prepare_url' => '/prepare',
            'panel_url' => '/panel',
            'token' => 'social_calendar',
        ]);
    }

    // The week: every coming quarter of an hour a drop zone at its moment, one gone by none, and each item laid at its quarter
    public function testTheWeekIsAGridOfQuartersOfAnHour(): void
    {
        $html = $this->render('week');

        $this->assertSame(2, substr_count($html, 'data-to="' . new \DateTimeImmutable('2026-10-04 14:15')->format('c') . '"'));
        $this->assertStringContainsString('social-week-cell is-past', $html);
        $this->assertStringContainsString('style="--row: 33; --lane: 0; --lanes: 2"', $html);
        $this->assertStringContainsString('style="--row: 34; --lane: 1; --lanes: 2"', $html);
        $this->assertStringContainsString('data-at="' . new \DateTimeImmutable('2026-10-04 14:15')->format('c') . '"', $html);
        $this->assertStringContainsString('class="social-week-hour">06:00', $html);
    }

    // A coming slot is a drop zone at its very moment, carrying the networks it posts on
    public function testEveryComingSlotIsADropZoneAtItsMoment(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-to="' . new \DateTimeImmutable('2026-10-04 19:00')->format('c') . '"', $html);
        $this->assertStringContainsString('data-networks="bluesky"', $html);
        $this->assertStringContainsString('class="social-calendar-card is-pinned"', $html);
    }

    // A free slot offers its preparation, a published card is not draggable, the drafts are
    public function testAFreeSlotOffersItsPreparationAndOnlyWaitingCardsDrag(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('label.social_calendar_prepare', $html);
        $this->assertStringContainsString('class="social-calendar-card is-published"', $html);
        $this->assertSame(3, substr_count($html, 'data-social-calendar-target="item"'));
        $this->assertStringContainsString('label.social_calendar_no_slot', $html);
    }

    // Each network shows as its own glyph on its colour, greyed while the site is not connected to it - one without a glyph keeps its initial
    public function testTheNetworksShowTheirIconAndWhetherTheyAreConnected(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<img src="/bundles/c975lsocial/icons/bluesky.svg" alt="">', $html);
        $this->assertStringContainsString('social-network-badge social-network-badge--linkedin is-off', $html);
        $this->assertStringContainsString('/management_social_connections', $html);
    }

    // The panel of a post of the queue: when it goes out, the field set to that moment, its texts, and the post's screen a link away
    public function testThePanelSaysWhenThePostGoesOutAndLetsItBePlanned(): void
    {
        $post = new SocialPost('gallery_media', '42', 'La sieste', 'https://example.org/42', null);
        new SocialPostTarget($post, 'bluesky', 'Le texte de Bluesky');
        $next = new \DateTimeImmutable('2026-10-04 19:00');

        $html = $this->twig()->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => ['id' => 7, 'title' => 'La sieste', 'image' => null, 'networks' => ['bluesky'], 'planned_at' => null, 'edit_url' => '/edit/7'],
            'state' => 'queued',
            'next' => $next,
            'plan_at' => $next,
            'targets' => $post->getTargets(),
            'connected' => ['bluesky'],
        ]);

        $this->assertStringContainsString('label.social_calendar_goes_out_next', $html);
        $this->assertStringContainsString('value="2026-10-04T19:00"', $html);
        $this->assertStringContainsString('data-action="social-calendar#plan" data-post-id="7"', $html);
        $this->assertStringContainsString('data-to="drafts"', $html);
        $this->assertStringContainsString('Le texte de Bluesky', $html);
        $this->assertStringContainsString('href="/edit/7"', $html);
    }
}
