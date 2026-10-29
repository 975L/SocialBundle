<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Templates;

use c975L\SocialBundle\Entity\SocialMedia;
use c975L\SocialBundle\Entity\SocialPost;
use c975L\SocialBundle\Entity\SocialPostTarget;
use c975L\SocialBundle\Entity\SocialSeries;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

// The calendar rendered for real, EasyAdmin's components included: a filter, a macro call or a component prop that does not exist only shows once the template runs, which a test reading its source would never see
class SocialCalendarTemplateTest extends TestCase
{
    private static ?TemplateTestKernel $kernel = null;

    // The kernel's Twig, booted once for the class - in debug, so a change to the kernel rebuilds its container
    private function twig(): Environment
    {
        if (null === self::$kernel) {
            self::$kernel = new TemplateTestKernel('test', true);
            self::$kernel->boot();
        }

        return self::$kernel->getContainer()->get('test.service_container')->get('twig');
    }

    private function render(string $view = 'month', ?string $feedUrl = null): string
    {
        $twig = $this->twig();

        $card = static fn (int $id, string $state, \DateTimeImmutable $at): array => ['id' => $id, 'title' => 'La sieste', 'image' => null, 'networks' => ['bluesky'], 'planned_at' => $at, 'state' => $state, 'edit_url' => '/edit/' . $id, 'video' => false];
        $evening = new \DateTimeImmutable('2026-10-04 19:00');
        $planned = new \DateTimeImmutable('2026-10-04 14:15');
        $items = [
            ['kind' => 'planned', 'at' => $planned] + $card(8, 'approved', $planned),
            ['kind' => 'planned', 'at' => $evening] + $card(9, 'draft', $evening),
            ['kind' => 'planned', 'at' => $evening, 'video' => true] + $card(10, 'failed', $evening),
        ];
        $published = [['kind' => 'published', 'at' => $evening->modify('-1 day')] + ['networks' => ['facebook']] + $card(7, 'published', $evening->modify('-2 days'))];
        // The week's two quarters of an hour around the planned post, the first one gone by
        $cells = [['at' => $planned->modify('-15 minutes'), 'open' => false], ['at' => $planned, 'open' => true]];
        $day = static fn (string $date, bool $open, array $dayItems, array $rows): array => ['date' => new \DateTimeImmutable($date), 'open' => $open, 'in_month' => true, 'today' => false, 'items' => $dayItems, 'cells' => 'month' !== $view ? $cells : [], 'rows' => 'month' !== $view ? $rows : []];

        return $twig->render('@c975LSocial/management/social_calendar.html.twig', [
            'view' => $view,
            'title' => '28 sept. - 4 oct. 2026',
            'date' => '2026-09-28',
            'day_date' => '2026-09-28',
            'hours' => 'month' !== $view ? ['06:00', '07:00'] : [],
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
            'medias_url' => '/medias',
            'upload_url' => '/upload',
            'texts_url' => '/texts',
            'networks_url' => '/networks',
            'duplicate_url' => '/duplicate',
            'retry_url' => '/retry',
            'open_id' => 0,
            'new_url' => '/new',
            'series_url' => '/series',
            'token' => 'social_calendar',
            'feed_url' => $feedUrl,
            'feed_token_url' => '/management/social-calendar/feed',
        ]);
    }

    // The panel of a post in the given state, its approval being what the state offers - with the medias given, the content's picture otherwise
    /**
     * @param list<SocialMedia>                                                                           $medias
     * @param array{previous: ?array{id: int, edit_url: string}, next: ?array{id: int, edit_url: string}} $siblings
     */
    private function panel(string $state, array $medias = [], ?string $image = null, bool $editable = true, int $seriesDrafts = 0, string $plannedAt = '2026-10-04 19:00', bool $textsSaved = false, string $mediaProblems = '', ?string $seriesUrl = null, array $siblings = ['previous' => null, 'next' => null]): string
    {
        $post = new SocialPost('gallery_media', '42', 'La sieste', 'https://example.org/42', null, new \DateTimeImmutable($plannedAt));
        $target = new SocialPostTarget($post, 'bluesky', 'Le texte de Bluesky');
        // A post published has gone out on its network, as the panel reads it
        if ('published' === $state) {
            $target->markPublished('ext-42');
        }
        if ('failed' === $state) {
            $target->markFailed('Missing required scope');
        }
        // Gone out, its first comment refused
        if ('commented' === $state) {
            $target->setComment('Le lien');
            $target->markPublished('ext-42');
            $target->markCommentFailed('Comment refused');
            $state = 'published';
        }

        return $this->twig()->render('@c975LSocial/management/_social_calendar_panel.html.twig', [
            'post' => ['id' => 7, 'title' => 'La sieste', 'image' => $image, 'networks' => ['bluesky'], 'planned_at' => $post->getPlannedAt(), 'state' => $state, 'edit_url' => '/edit/7'],
            'state' => $state,
            'medias' => $medias,
            'editable' => $editable,
            'pick_media_url' => '/pick/7',
            'change_content_url' => '/change/7',
            'targets' => $post->getTargets(),
            'max_lengths' => [300],
            'texts_saved' => $textsSaved,
            'media_problems' => $mediaProblems,
            'series_drafts' => $seriesDrafts,
            'series_url' => $seriesUrl,
            'siblings' => $siblings,
            'connected' => ['bluesky'],
            'offered_networks' => ['bluesky', 'facebook'],
            'accepted_types' => 'image/jpeg,video/mp4',
            'networks' => $post->getNetworks(),
        ]);
    }

    // A refused network named on the badge, its reason and its own button to send it again under its text
    public function testARefusedNetworkIsNamedAndOfferedAgain(): void
    {
        $html = $this->panel('failed');

        $this->assertStringContainsString('label.social_calendar_state_failed_on', $html);
        $this->assertStringContainsString('data-post-id="7" data-state="failed"', $html);
        $this->assertStringContainsString('Missing required scope', $html);
        $this->assertStringContainsString('data-action="social-calendar#retry" data-post-id="7" data-network="bluesky"', $html);
        $this->assertStringContainsString('label.social_calendar_retry', $html);
        $this->assertStringNotContainsString('social-calendar#retry', $this->panel('approved'));
    }

    // A first comment refused on a post gone out says only the comment goes again, its button too
    public function testARefusedCommentIsOfferedAgainAsTheCommentAlone(): void
    {
        $html = $this->panel('commented');

        $this->assertStringContainsString('email.social_post_comment_failed', $html);
        $this->assertStringContainsString('label.social_calendar_retry_comment', $html);
        $this->assertStringContainsString('data-action="social-calendar#retry" data-post-id="7" data-network="bluesky"', $html);
    }

    // The day: the week's grid narrowed to one column, its button pressed - a day's heading in the week and in the month opening it
    public function testTheDayIsTheWeeksGridOnOneColumn(): void
    {
        $html = $this->render('day');

        $this->assertStringContainsString('social-week social-week--day', $html);
        $this->assertStringContainsString('class="social-week-hour">06:00', $html);
        $this->assertStringContainsString('btn-primary" role="button" href="/management_social_calendar?view=day&amp;date=2026-09-28"', $html);
        $this->assertStringContainsString('view=day&amp;date=2026-10-04', $this->render('week'));
        $this->assertStringContainsString('view=day&amp;date=2026-10-04', $this->render('month'));
        $this->assertStringNotContainsString('social-week--day', $this->render('week'));
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
        $this->assertMatchesRegularExpression('#<[^>]*(?=[^>]*href="/new")(?=[^>]*social-calendar-new)[^>]*>#', $month);
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

    // A network's text waiting to go out is corrected in the panel, held to its network's limit, the texts saved together
    public function testThePanelLetsTheTextsBeCorrected(): void
    {
        $html = $this->panel('draft');

        $this->assertStringContainsString('data-action="social-calendar#saveTexts" data-post-id="7"', $html);
        $this->assertMatchesRegularExpression('#<textarea[^>]*name="texts\[[^\]]*\]"[^>]*maxlength="300"[^>]*>Le texte de Bluesky</textarea>#', $html);
        $this->assertStringContainsString('label.social_calendar_save_texts', $html);
        $this->assertStringContainsString('for="social-calendar-text-', $html);
        $this->assertStringNotContainsString('label.social_calendar_texts_saved', $html);
    }

    // The panel answered to the texts saved says so
    public function testThePanelSaysTheTextsWereSaved(): void
    {
        $this->assertStringContainsString('label.social_calendar_texts_saved', $this->panel('draft', textsSaved: true));
    }

    // What a network would refuse in the medias is warned at the top of the panel, one line each, and nothing when they suit
    public function testThePanelWarnsOfTheMediasANetworkWouldRefuse(): void
    {
        $this->assertMatchesRegularExpression('#alert-warning.*Instagram : one<br />\s*Facebook : two#s', $this->panel('draft', mediaProblems: "Instagram : one\nFacebook : two"));
        $this->assertStringNotContainsString('alert-warning', $this->panel('draft'));
    }

    // The panel of an approved post offers to take it back to a draft instead
    public function testThePanelOfAnApprovedPostOffersItsWithdrawal(): void
    {
        $html = $this->panel('approved');

        $this->assertStringContainsString('label.social_calendar_state_approved', $html);
        $this->assertStringContainsString('data-to="unapprove"', $html);
        $this->assertStringNotContainsString('data-to="approve"', $html);
    }

    // A post of a series offers its coming drafts approved at once, counted; a post of none, or of a series all approved, does not
    public function testThePanelOffersTheSeriesApprovalWhileItHasDrafts(): void
    {
        $this->assertStringNotContainsString('approve-series', $this->panel('draft'));

        $html = $this->panel('published', seriesDrafts: 4);

        $this->assertStringContainsString('data-post-id="7" data-to="approve-series"', $html);
        $this->assertStringContainsString('label.social_calendar_approve_series', $html);
    }

    // A coming draft that is the series' last one already has its own "Approve": the series' is not offered beside it - a draft gone by, or a post approved, not being counted
    public function testThePanelOfTheSeriesLastDraftOffersItsOwnApprovalOnly(): void
    {
        $this->assertStringNotContainsString('approve-series', $this->panel('draft', seriesDrafts: 1, plannedAt: '+1 day'));
        $this->assertStringContainsString('approve-series', $this->panel('draft', seriesDrafts: 2, plannedAt: '+1 day'));
        $this->assertStringContainsString('approve-series', $this->panel('draft', seriesDrafts: 1));
        $this->assertStringContainsString('approve-series', $this->panel('approved', seriesDrafts: 1, plannedAt: '+1 day'));
    }

    // The legend shows each state as a card looks, never taken for a card to drag
    public function testTheLegendShowsEveryState(): void
    {
        $html = $this->render();

        foreach (['draft', 'approved', 'failed', 'published'] as $state) {
            $this->assertStringContainsString('class="social-calendar-card is-' . $state . ' is-legend"', $html);
        }
    }

    // A post gone out everywhere is only read: no moment to set, no approval to change
    public function testThePanelOfAPublishedPostOnlyShowsIt(): void
    {
        $html = $this->panel('published');

        $this->assertStringContainsString('label.social_calendar_state_published', $html);
        $this->assertStringNotContainsString('social-calendar#plan', $html);
        $this->assertStringNotContainsString('data-to=', $html);
        $this->assertStringContainsString('Le texte de Bluesky', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('for="social-calendar-text-', $html);
        $this->assertStringNotContainsString('label.social_calendar_save_texts', $html);
    }

    // Every media of the post is shown in its order, each dragged and taken off while the post waits; a post gone out on a network only shows them
    public function testThePanelShowsEveryMediaAndLetsThemBeOrderedAndTakenOff(): void
    {
        $medias = [
            new SocialMedia()->setFilename('social/a.jpg')->setMimeType('image/jpeg')->setAlt('Le lac'),
            new SocialMedia()->setFilename('social/b.mp4')->setMimeType('video/mp4'),
        ];

        $draft = $this->panel('draft', $medias, '/social/a.jpg');
        $this->assertMatchesRegularExpression('#<a href="/social/a.jpg" class="image-zoom__link" data-action="imageZoom\#open[^"]*" aria-label="label.social_calendar_media_zoom">\s*<img src="/social/a.jpg" [^>]*alt="Le lac".*<video src="/social/b.mp4\#t=1"#s', $draft);
        $this->assertStringContainsString('tabindex="0" role="button" data-action="click->social-calendar#zoom keydown.enter->social-calendar#zoom keydown.space->social-calendar#zoom"', $draft);
        $this->assertSame(1, substr_count($draft, 'social-calendar-panel-play'));
        $this->assertSame(2, substr_count($draft, 'data-social-calendar-target="media" data-post-id="7"'));
        $this->assertSame(2, substr_count($draft, 'data-action="social-calendar#removeMedia" data-post-id="7"'));
        $this->assertStringContainsString('data-social-calendar-target="thumbnail" data-post-id="7" data-image="/social/a.jpg"', $draft);
        $this->assertStringContainsString('href="/pick/7"', $draft);
        $this->assertStringContainsString('href="/change/7"', $draft);

        $published = $this->panel('failed', $medias, '/social/a.jpg', false);
        $this->assertStringContainsString('<img src="/social/a.jpg"', $published);
        $this->assertStringNotContainsString('data-social-calendar-target="media"', $published);
        $this->assertStringNotContainsString('social-calendar#removeMedia', $published);
        $this->assertStringNotContainsString('href="/pick/7"', $published);
    }

    // A post with no media of its own shows the picture of its content, opened larger on a click
    public function testThePanelShowsTheContentsPictureWhenThePostHasNoMedia(): void
    {
        $html = $this->panel('draft', [], '/medias/gallery/lac.webp');

        $this->assertMatchesRegularExpression('#<a href="/medias/gallery/lac.webp" class="image-zoom__link" data-action="imageZoom\#open[^"]*"[^>]*>\s*<img src="/medias/gallery/lac.webp" class="img img-responsive social-calendar-panel-content"#', $html);
        $this->assertStringNotContainsString('social-calendar#removeMedia', $html);
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

    // The connected networks are ticked in the panel, the ones the post goes to checked, while it has not gone out anywhere - then shown as they stand
    public function testThePanelLetsTheNetworksBeTicked(): void
    {
        $html = $this->panel('draft');

        $this->assertStringContainsString('data-action="social-calendar#saveNetworks" data-post-id="7"', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="networks\[\]" value="bluesky"[^>]*checked>#', $html);
        $this->assertMatchesRegularExpression('#<input[^>]*name="networks\[\]" value="facebook"[^>]*>#', $html);
        $this->assertDoesNotMatchRegularExpression('#value="facebook"[^>]*checked#', $html);
        $this->assertStringContainsString('label.social_calendar_save_networks', $html);

        $frozen = $this->panel('published', editable: false);
        $this->assertMatchesRegularExpression('#<fieldset[^>]* disabled>#', $frozen);
        $this->assertStringNotContainsString('label.social_calendar_save_networks', $frozen);
    }

    // Any post is offered to be duplicated, one gone out as well, and its card's badges and state come with the panel
    public function testThePanelOffersTheDuplicateAndTheCardsBadges(): void
    {
        foreach (['draft', 'published'] as $state) {
            $this->assertStringContainsString('data-action="social-calendar#duplicate" data-post-id="7"', $this->panel($state, editable: 'draft' === $state));
        }
        $this->assertMatchesRegularExpression('#<template data-social-calendar-target="badges" data-post-id="7" data-state="draft">\s*<span class="social-network-badges">#', $this->panel('draft'));
    }

    // A post still to go out offers a zone to drop its own pictures and videos on, its file picker taking the accepted types alone - not one gone out
    public function testThePanelOffersAZoneToDropMediasOn(): void
    {
        $html = $this->panel('draft');

        $this->assertMatchesRegularExpression('#<label class="social-calendar-panel-drop" data-social-calendar-target="drop" data-post-id="7" data-uploading="label.social_calendar_uploading">#', $html);
        $this->assertStringContainsString('<input type="file" class="visually-hidden" multiple accept="image/jpeg,video/mp4" data-action="social-calendar#pickFiles">', $html);
        $this->assertStringContainsString('data-social-calendar-upload-url-value="/upload"', $this->render('week'));
        $this->assertStringNotContainsString('social-calendar-panel-drop', $this->panel('published', editable: false));
    }

    // Escape closes the panel, from anywhere on the screen
    // The coming posts of its series around it open in the same panel, nothing offered past either end
    public function testThePanelStepsToTheSeriesPostsAround(): void
    {
        $html = $this->panel('draft', siblings: ['previous' => null, 'next' => ['id' => 8, 'edit_url' => '/edit/8']]);

        $this->assertMatchesRegularExpression('/href="\/edit\/8"[^>]*data-action="social-calendar#open" data-panel-id="8"/', $html);
        $this->assertStringContainsString('label.social_calendar_panel_next', $html);
        $this->assertStringNotContainsString('label.social_calendar_panel_previous', $html);
    }

    public function testEscapeClosesThePanel(): void
    {
        $this->assertStringContainsString('data-action="keydown.esc@window->social-calendar#closePanel"', $this->render('week'));
    }

    // A post a video goes out with says so on its card, its picture showing nothing of it
    public function testACardSaysAVideoGoesWithThePost(): void
    {
        $html = $this->render();

        $this->assertSame(1, substr_count($html, 'social-calendar-card-video'));
        $this->assertMatchesRegularExpression('/data-panel-id="10"[^>]*>\s*<i class="fa fa-film social-calendar-card-video" role="img" title="label.social_calendar_has_video" aria-label="label.social_calendar_has_video"><\/i>/', $html);
    }

    // The panel of a post of a series leads to its posts side by side, one of no series to nothing
    public function testThePanelOfASeriesPostLeadsToTheSeries(): void
    {
        $this->assertStringContainsString('href="/series/3"', $this->panel('draft', seriesUrl: '/series/3'));
        $this->assertStringNotContainsString('label.social_calendar_series_view', $this->panel('draft'));
    }

    // A series' coming posts side by side, each with its approval, its networks to tick and its picture or video to open
    /** @param list<array<string, mixed>> $posts */
    private function series(array $posts): string
    {
        $series = new SocialSeries('Photo du jour', 3, 'days', 1, [], 'text', "Photo du jour\n#photo", [], ['bluesky']);

        return $this->twig()->render('@c975LSocial/management/social_calendar_series.html.twig', [
            'series' => $series,
            'posts' => $posts,
            'approved' => \count(array_filter($posts, static fn (array $post): bool => 'approved' === $post['state'])),
            'to_approve' => \count(array_filter($posts, static fn (array $post): bool => 'approved' !== $post['state'])),
            'last' => new \DateTimeImmutable('2026-12-05 09:00'),
            'others' => [['series' => $series, 'lastPlannedAt' => new \DateTimeImmutable('2026-12-05 09:00')], ['series' => new SocialSeries('N&B', 3, 'days', 1, [], 'text', 'N&B', [], ['bluesky']), 'lastPlannedAt' => new \DateTimeImmutable('2026-10-30 15:00')]],
            'connected' => ['bluesky', 'facebook'],
            'offered_networks' => ['bluesky', 'facebook'],
            'move_url' => '/move',
            'networks_url' => '/networks',
            'panel_url' => '/panel',
            'medias_url' => '/medias',
            'upload_url' => '/upload',
            'texts_url' => '/texts',
            'retry_url' => '/retry',
            'duplicate_url' => '/duplicate',
            'open_id' => 0,
            'token' => 'social_calendar',
            'prolong_url' => '/prolong',
        ]);
    }

    /** @return array<string, mixed> */
    private function seriesCard(int $id, string $state, ?string $videoUrl = null, bool $editable = true): array
    {
        return ['id' => $id, 'title' => 'Feuilles ' . $id, 'image' => '/photo-' . $id . '.webp', 'video' => null !== $videoUrl, 'video_url' => $videoUrl, 'networks' => ['bluesky'], 'ticked' => ['bluesky'], 'planned_at' => new \DateTimeImmutable('2026-11-0' . $id . ' 09:00'), 'state' => $state, 'edit_url' => '/edit/' . $id, 'editable' => $editable];
    }

    public function testTheSeriesShowsEachPostWithItsApproval(): void
    {
        $html = $this->series([$this->seriesCard(1, 'draft'), $this->seriesCard(2, 'approved')]);

        $this->assertMatchesRegularExpression('/data-post-id="1" data-to="approve"/', $html);
        $this->assertMatchesRegularExpression('/data-post-id="2" data-to="unapprove"/', $html);
        $this->assertStringContainsString('label.social_series_review_approved_button', $html);
        $this->assertStringContainsString('href="/edit/1"', $html);
        $this->assertStringContainsString('href="/prolong"', $html);
        $this->assertStringContainsString('label.info_social_series_review', $html);
        $this->assertSame(2, substr_count($html, 'class="social-series-review-card'));
    }

    // Each connected network a toggle, ticked when the post goes there, saved on a change
    public function testTheSeriesTicksEachPostsNetworks(): void
    {
        $html = $this->series([$this->seriesCard(1, 'draft')]);

        $this->assertStringContainsString('data-action="change->social-calendar#toggleNetworks" data-post-id="1"', $html);
        $this->assertMatchesRegularExpression('/value="bluesky" id="social-series-network-1-bluesky" checked>/', $html);
        $this->assertMatchesRegularExpression('/value="facebook" id="social-series-network-1-facebook">/', $html);
    }

    // A picture or a title opens the post's panel, a video plays at its size
    public function testTheSeriesOpensPicturesAndVideos(): void
    {
        $html = $this->series([$this->seriesCard(1, 'draft'), $this->seriesCard(2, 'draft', '/video-2.mp4')]);

        $this->assertStringContainsString('src="/video-2.mp4#t=1"', $html);
        $this->assertStringContainsString('click->social-calendar#zoom', $html);
        $this->assertMatchesRegularExpression('/href="\/edit\/1" data-action="social-calendar#open" data-panel-id="1"[^>]*>\s*<img src="\/photo-1.webp"/', $html);
        $this->assertSame(3, substr_count($html, 'data-action="social-calendar#open"'));
        $this->assertStringContainsString('data-social-calendar-panel-url-value="/panel"', $html);
        $this->assertStringContainsString('data-social-calendar-target="panel"', $html);
        $this->assertStringContainsString('data-social-calendar-target="zoom"', $html);
    }

    // A post with neither picture nor video says so on its card, which opens its panel all the same - the warning always there, a picture before it hiding it, so the panel only adds or removes the picture
    public function testTheSeriesSaysAPostHasNoPicture(): void
    {
        $html = $this->series([['image' => null] + $this->seriesCard(1, 'draft'), $this->seriesCard(2, 'draft')]);

        $this->assertMatchesRegularExpression('/data-panel-id="1" data-panel-card[^>]*>\s*<span class="social-series-review-no-image">/', $html);
        $this->assertMatchesRegularExpression('/<img src="\/photo-2.webp"[^>]*>\s*<span class="social-series-review-no-image">/', $html);
    }

    // A post gone out somewhere keeps its networks, and a series with nothing to come says so
    public function testTheSeriesLocksAPostGoneOutAndSaysWhenEmpty(): void
    {
        $this->assertMatchesRegularExpression('/<fieldset disabled>/', $this->series([$this->seriesCard(1, 'draft', editable: false)]));
        $this->assertStringContainsString('label.social_series_review_empty', $this->series([]));
    }

    // The cards left to approve show first, every card when none is left
    public function testTheSeriesFiltersOnWhatIsLeftToApprove(): void
    {
        $this->assertMatchesRegularExpression('/id="social-series-filter-todo" checked/', $this->series([$this->seriesCard(1, 'draft'), $this->seriesCard(2, 'approved')]));
        $this->assertMatchesRegularExpression('/id="social-series-filter-all" checked/', $this->series([$this->seriesCard(1, 'approved')]));
    }

    // Every draft checked to begin with, a box to check or uncheck them all, and one button approving those checked - an approved or failed post having no box
    public function testTheSeriesApprovesTheDraftsPicked(): void
    {
        $html = $this->series([$this->seriesCard(1, 'draft'), $this->seriesCard(2, 'approved'), $this->seriesCard(3, 'failed')]);

        $this->assertSame(1, substr_count($html, 'data-social-calendar-target="pick"'));
        $this->assertMatchesRegularExpression('/checked value="1" data-social-calendar-target="pick" data-action="social-calendar#syncPicks"/', $html);
        $this->assertStringContainsString('data-social-calendar-target="pickAll" data-action="social-calendar#pickAll"', $html);
        $this->assertStringContainsString('data-action="social-calendar#approvePicked"', $html);
        $this->assertStringNotContainsString('social-calendar#pickAll', $this->series([$this->seriesCard(2, 'approved'), $this->seriesCard(3, 'failed')]));
    }
}
