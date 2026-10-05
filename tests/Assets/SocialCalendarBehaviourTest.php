<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\SocialBundle\Tests\Assets;

use PHPUnit\Framework\Attributes\Group;

// assets/js/social-calendar.js dragged with a real pointer over a calendar the browser really lays out, where a card lands being hit-tested under the pointer. The reload following a save is cancelled through the "saved" event, or it would take the document this suite shares out from under it
#[Group('browser')]
class SocialCalendarBehaviourTest extends JsCase
{
    private const string URL = '/management/social-calendar/move';

    private const string CSS = '
        [data-social-calendar-target=zone] { display: block; width: 200px; min-height: 60px; margin: 10px; }
        [data-social-calendar-target=item] { display: block; width: 150px; height: 20px; }
        .social-week-body { position: relative; width: 200px; margin: 10px; }
        .social-week-body [data-social-calendar-target=zone] { height: 30px; min-height: 0; margin: 0; }
        .social-week-items { position: absolute; top: 0; left: 0; z-index: 1; }
    ';

    // A card dropped on a day of the month sends that day alone, under the screen's token: the server keeps the post's time of day there
    public function testACardDroppedOnADayOfTheMonthSendsTheDayAlone(): void
    {
        $sent = $this->calendar('await drag("draft", "d1005"); return window.__sent;');

        $this->assertSame(self::URL, $sent['url'], 'The move was not saved where the screen said to save it.');
        $this->assertSame('post=7&to=2026-10-05', $sent['body'], 'What was sent is not the post and the day it was dropped on.');
        $this->assertSame('jeton', $sent['token'], 'The move is saved without the token the screen was given.');
    }

    // On the week's grid, a card goes to the quarter of an hour under the pointer - the card laid over that grid never hiding the quarter it stands on
    public function testACardDroppedOnAQuarterOfAnHourIsPlannedThere(): void
    {
        $this->assertSame('post=8&to=2026-10-04T14%3A30%3A00%2B02%3A00', $this->calendar('await drag("planned", "q1430"); return window.__sent.body;'), 'A card dropped on a quarter of an hour was not planned at that moment.');
    }

    // Dropped back on the quarter of an hour it already stands at, a card saves nothing
    public function testACardDroppedOnItsOwnQuarterSavesNothing(): void
    {
        $this->assertSame(0, $this->calendar('await drag("planned", "q1415"); return window.__saves;'), 'A card put back on its own quarter of an hour saved a move nobody made.');
    }

    // The card follows the pointer without leaving its place, so a week's grid keeps its layout under it - and comes back to it once let go
    public function testTheCardFollowsThePointerAndComesBack(): void
    {
        $moved = $this->calendar(
            'const card = item("draft");
             const start = card.getBoundingClientRect();
             point(card, "pointerdown", start.left + 5, start.top + 5);
             point(document, "pointermove", start.left + 5, start.top + 50);
             point(document, "pointermove", start.left + 5, start.top + 80);
             await frame();
             const during = card.style.transform;
             point(document, "pointercancel", start.left + 5, start.top + 80);
             await frame();

             return { during, after: card.style.transform, home: card.parentElement.dataset.to };'
        );

        $this->assertSame('translate(0px, 30px)', $moved['during'], 'The card does not follow the pointer.');
        $this->assertSame('', $moved['after'], 'The card stays where it was dragged to.');
        $this->assertSame('2026-10-04', $moved['home'], 'The card left its place while dragged.');
    }

    // A click on a card opens its panel beside the calendar rather than leaving it
    public function testAClickOpensThePanel(): void
    {
        $opened = $this->calendar('item("draft").click(); await frame(); await frame(); return { url: window.__panel, hidden: item("panel").hidden };');

        $this->assertStringEndsWith('/management/social-calendar/post?id=7', (string) $opened['url'], 'The panel was not asked for the card clicked.');
        $this->assertFalse($opened['hidden'], 'The panel stays hidden once read.');
    }

    // The moment typed in the panel goes out as typed, the server reading it in its own time zone; its approval and its withdrawal go through the same move
    public function testThePanelSendsTheMomentAsTypedAndItsApproval(): void
    {
        $sent = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             item("plan").click(); await frame();
             const planned = window.__sent.body;
             item("approve").click(); await frame();
             const approved = window.__sent.body;
             item("unapprove").click(); await frame();

             return { planned, approved, unapproved: window.__sent.body };'
        );

        // No time zone of the browser's: the field shows the server's time, which is sent back untouched
        $this->assertSame('post=7&to=2026-10-05T14%3A20', $sent['planned'], 'The moment typed was not sent as typed.');
        $this->assertSame('post=7&to=approve', $sent['approved'], 'The approval was not sent through the move.');
        $this->assertSame('post=7&to=unapprove', $sent['unapproved'], 'The withdrawal was not sent through the move.');
    }

    // A card put back on the day it was picked up from saves nothing
    public function testACardDroppedWhereItWasSavesNothing(): void
    {
        $this->assertSame(0, $this->calendar('await drag("draft", "d1004"); return window.__saves;'), 'Picking a card up and putting it back saved a move nobody made.');
    }

    // A cancelled gesture puts the card back and saves nothing
    public function testACancelledDragPutsTheCardBack(): void
    {
        $cancelled = $this->calendar('await drag("draft", "d1005", true); return { saves: window.__saves, home: item("draft").parentElement.dataset.to };');

        $this->assertSame(0, $cancelled['saves'], 'A cancelled drag saved a move the user gave up on.');
        $this->assertSame('2026-10-04', $cancelled['home'], 'A cancelled drag left the card where it had been dragged to.');
    }

    // A coming moment double-clicked opens a new post planned there, a day of the month carrying the day alone
    public function testADoubleClickOnAMomentWritesAPostThere(): void
    {
        $urls = $this->calendar(
            'const opened = [];
             document.addEventListener("social-calendar:create", (event) => { event.preventDefault(); opened.push(event.detail.url); });
             item("q1430").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));
             item("d1005").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));

             return opened;'
        );

        $this->assertSame(['https://example.org/management/social-post/new?at=2026-10-04T14%3A30%3A00%2B02%3A00', 'https://example.org/management/social-post/new?at=2026-10-05'], $urls, 'A double click did not open a new post at its moment.');
    }

    // A card double-clicked keeps opening its own post, never a new one under it
    public function testADoubleClickOnACardWritesNothing(): void
    {
        $this->assertSame(0, $this->calendar(
            'let opened = 0;
             document.addEventListener("social-calendar:create", (event) => { event.preventDefault(); opened += 1; });
             item("draft").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));

             return opened;'
        ), 'A card double-clicked opened a new post.');
    }

    // A move the server refuses says its reason - a media a network does not take - or the screen's own words when it gave none
    public function testARefusalSaysTheServersReason(): void
    {
        $alerts = $this->calendar(
            'const alerts = [];
             window.alert = (message) => alerts.push(message);
             window.__refusal = "Instagram : une photo au moins";
             await drag("draft", "d1005"); await frame();
             window.__refusal = "";
             await drag("draft", "d1005"); await frame();

             return alerts;'
        );

        $this->assertSame(['Instagram : une photo au moins', 'Echec'], $alerts, 'A refused move did not say why.');
    }

    private function calendar(string $probe): mixed
    {
        $preamble = 'const item = (name) => root.querySelector(`[data-name=${name}]`);
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             const point = (el, type, x, y) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, pointerType: "mouse", button: 0, buttons: 1, clientX: x, clientY: y }));
             // A drag as a mouse makes one: pressed on the card, moved past the threshold and over the zone it lands on, then let go
             const drag = async (card, zone, cancelled) => {
                 const start = item(card).getBoundingClientRect();
                 point(item(card), "pointerdown", start.left + 5, start.top + 5);
                 const target = item(zone).getBoundingClientRect();
                 point(document, "pointermove", target.left + 5, target.bottom - 5);
                 await frame();
                 point(document, cancelled ? "pointercancel" : "pointerup", target.left + 5, target.bottom - 5);
                 await frame();
                 await frame();
             }; ';

        return $this->observe(
            $this->page(),
            ['social-calendar' => 'social-calendar'],
            $preamble . $probe,
            [
                'css' => self::CSS,
                // The move route answered by the scenario, which is also what keeps this test off the network
                'before' => 'window.__saves = 0;
                    window.__sent = null;
                    window.fetch = (url, options) => {
                        // The panel of a card, read with a GET: what _social_calendar_panel.html.twig gives
                        if (!options) {
                            window.__panel = String(url);

                            return Promise.resolve({ ok: true, text: () => Promise.resolve(\'<input type="datetime-local" data-social-calendar-target="planAt" value="2026-10-05T14:20"><button data-name="plan" data-action="social-calendar#plan" data-post-id="7">Plan</button><button data-name="approve" data-action="social-calendar#place" data-post-id="7" data-to="approve">Approve</button><button data-name="unapprove" data-action="social-calendar#place" data-post-id="7" data-to="unapprove">Unapprove</button><button data-name="close" data-action="social-calendar#closePanel">Close</button>\') });
                        }
                        window.__saves += 1;
                        window.__sent = { url, body: String(options.body), token: options.headers["X-CSRF-Token"] };

                        // A refusal when the scenario sets one, its reason as the body
                        return Promise.resolve(undefined === window.__refusal ? { ok: true } : { ok: false, text: () => Promise.resolve(window.__refusal) });
                    };
                    document.addEventListener("social-calendar:saved", (event) => event.preventDefault());',
            ]
        );
    }

    // Two days of the month and two quarters of an hour of the week, as social_calendar.html.twig renders them
    private function page(): string
    {
        return '<div data-controller="social-calendar" data-social-calendar-panel-url-value="/management/social-calendar/post" data-social-calendar-url-value="' . self::URL . '" data-social-calendar-new-url-value="https://example.org/management/social-post/new" data-social-calendar-token-value="jeton" data-social-calendar-failed-label-value="Echec">
                <div data-name="d1004" data-social-calendar-target="zone" data-to="2026-10-04" data-action="dblclick->social-calendar#create">
                    <a data-name="draft" href="#" draggable="false" data-social-calendar-target="item" data-action="social-calendar#open" data-panel-id="7" data-post-id="7" data-at="2026-10-04T19:00:00+02:00" class="social-calendar-card is-draft">Draft</a>
                </div>
                <div data-name="d1005" data-social-calendar-target="zone" data-to="2026-10-05" data-action="dblclick->social-calendar#create"></div>
                <div class="social-week-body">
                    <div data-name="q1415" data-social-calendar-target="zone" data-to="2026-10-04T14:15:00+02:00"></div>
                    <div data-name="q1430" data-social-calendar-target="zone" data-to="2026-10-04T14:30:00+02:00" data-action="dblclick->social-calendar#create"></div>
                    <div class="social-week-items">
                        <a data-name="planned" href="#" draggable="false" data-social-calendar-target="item" data-post-id="8" data-at="2026-10-04T14:15:00+02:00" class="social-calendar-card is-approved">Planned</a>
                    </div>
                </div>
                <aside data-name="panel" data-social-calendar-target="panel" hidden></aside>
            </div>';
    }
}
