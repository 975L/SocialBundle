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
        .medias { display: flex; margin: 0; padding: 0; list-style: none; }
        .medias li { width: 60px; height: 60px; }
        [data-name=draft] img { width: 5px; height: 5px; }
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

    // A media dragged past another takes its place, the order as it now stands saved on the spot; the panel answered is shown, and the card takes the post's picture as it now is
    public function testAMediaDraggedInThePanelSavesTheNewOrder(): void
    {
        $sent = $this->calendar(
            '// Left set by a scenario refused before this one, the suite sharing its document
             window.__refusal = undefined;
             item("draft").click(); await frame(); await frame();
             const from = item("m1").getBoundingClientRect();
             const to = item("m3").getBoundingClientRect();
             point(item("m1"), "pointerdown", from.left + 5, from.top + 5);
             point(document, "pointermove", to.right - 5, to.top + 5);
             await frame();
             point(document, "pointerup", to.right - 5, to.top + 5);
             await frame(); await frame();

             return { ...window.__sent, saves: window.__saves, picture: item("draft").querySelector("img").getAttribute("src"), panel: item("panel").innerHTML };'
        );

        $this->assertSame('/management/social-calendar/medias', $sent['url'], 'The order was not saved where the screen said to save it.');
        $this->assertSame('post=7&medias%5B%5D=2&medias%5B%5D=3&medias%5B%5D=1', $sent['body'], 'What was sent is not the order the medias were left in.');
        $this->assertSame('jeton', $sent['token'], 'The order is saved without the token the screen was given.');
        $this->assertSame(1, $sent['saves']);
        $this->assertStringContainsString('data-image="/social/3.jpg"', $sent['panel'], 'The panel does not show what the server answered.');
        $this->assertSame('/social/3.jpg', $sent['picture'], 'The card kept the picture the post no longer opens with.');
    }

    // A media's gesture cancelled - a call coming in, a palm rejected - puts the medias back in the order they stood, nothing saved
    public function testACancelledMediaDragPutsTheMediasBack(): void
    {
        $cancelled = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             const from = item("m1").getBoundingClientRect();
             const to = item("m3").getBoundingClientRect();
             point(item("m1"), "pointerdown", from.left + 5, from.top + 5);
             point(document, "pointermove", to.right - 5, to.top + 5);
             await frame();
             const moved = [...item("m1").parentElement.children].map((media) => media.dataset.mediaId).join();
             point(document, "pointercancel", to.right - 5, to.top + 5);
             await frame(); await frame();

             return { moved, order: [...item("m1").parentElement.children].map((media) => media.dataset.mediaId).join(), saves: window.__saves };'
        );

        $this->assertSame('2,3,1', $cancelled['moved'], 'The media did not follow the pointer, so the cancellation proves nothing.');
        $this->assertSame('1,2,3', $cancelled['order'], 'A cancelled drag left the medias in an order that was never saved.');
        $this->assertSame(0, $cancelled['saves'], 'A cancelled drag saved an order the user gave up on.');
    }

    // The card loses its picture with the post's last one and gains one when it had none, the icons of its networks left alone
    public function testTheCardLosesAndGainsItsPicture(): void
    {
        $card = $this->calendar(
            'window.__refusal = undefined;
             window.confirm = () => true;
             window.__image = "";
             item("draft").click(); await frame(); await frame();
             item("remove").click(); await frame(); await frame();
             const lost = null === item("draft").querySelector(":scope > img");
             const icon = null !== item("icon");
             window.__image = "/social/9.jpg";
             item("draft").click(); await frame(); await frame();
             item("remove").click(); await frame(); await frame();
             window.__image = undefined;
             const picture = item("draft").firstElementChild;

             return { lost, icon, tag: picture.tagName, src: picture.getAttribute("src"), alt: picture.getAttribute("alt"), loading: picture.getAttribute("loading"), iconSrc: item("icon").getAttribute("src") };'
        );

        $this->assertTrue($card['lost'], 'The card kept a picture whose file went with the last media.');
        $this->assertTrue($card['icon'], 'The icon of a network went with the picture of the card.');
        $this->assertSame(['IMG', '/social/9.jpg', '', 'lazy'], [$card['tag'], $card['src'], $card['alt'], $card['loading']], 'A card without a picture did not gain the one the post now opens with.');
        $this->assertSame('data:,', $card['iconSrc'], 'The icon of a network was taken for the picture of the card.');
    }

    // A media put back where it was saves nothing, and one taken off is sent only once confirmed
    public function testAMediaIsTakenOffOnlyOnceConfirmed(): void
    {
        $sent = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             const box = item("m1").getBoundingClientRect();
             point(item("m1"), "pointerdown", box.left + 5, box.top + 5);
             point(document, "pointermove", box.left + 15, box.top + 15);
             point(document, "pointerup", box.left + 15, box.top + 15);
             await frame();
             window.confirm = () => false;
             item("remove").click(); await frame();
             const refused = window.__saves;
             window.confirm = () => true;
             item("remove").click(); await frame();

             return { refused, body: window.__sent.body };'
        );

        $this->assertSame(0, $sent['refused'], 'A media was saved without having moved, or taken off without being confirmed.');
        $this->assertSame('post=7&remove=2', $sent['body'], 'The media taken off is not the one whose button was clicked.');
    }

    // A video of the panel clicked plays over the page from its start, with its controls, and a click beside it closes it and empties it
    public function testAVideoOfThePanelPlaysFromItsStartAndCloses(): void
    {
        $zoom = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             item("playable").click(); await frame();
             const shown = item("zoom").querySelector("video");
             const opened = { open: item("zoom").open, controls: shown?.controls, autoplay: shown?.autoplay, src: shown && new URL(shown.src).pathname + new URL(shown.src).hash };
             item("zoom").click(); await frame();

             return { ...opened, closed: !item("zoom").open, left: item("zoom").children.length, panel: !item("panel").hidden };'
        );

        $this->assertSame(['open' => true, 'controls' => true, 'autoplay' => true, 'src' => '/v.mp4'], array_intersect_key($zoom, array_flip(['open', 'controls', 'autoplay', 'src'])), 'The video clicked did not play over the page from its start, with its controls.');
        $this->assertTrue($zoom['closed'], 'A click beside the video did not close it.');
        $this->assertSame(0, $zoom['left'], 'The video closed was left in the page, still playing.');
        $this->assertTrue($zoom['panel'], 'Closing the video closed the panel too.');
    }

    // Escape over a video or a picture of the panel closes that alone, the panel and its unsaved texts kept
    public function testEscapeOnTheZoomKeepsThePanel(): void
    {
        $kept = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             item("playable").click(); await frame();
             window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
             const video = !item("panel").hidden;
             item("zoom").close(); await frame();
             item("picture").showModal();
             window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

             return { video, picture: !item("panel").hidden };'
        );

        $this->assertSame(['video' => true, 'picture' => true], $kept, 'Escape over a video or a picture of the panel closed the panel too.');
    }

    // The networks ticked are sent with the post, the button held meanwhile, and the card takes the badges the panel answers with
    public function testTheNetworksTickedAreSavedAndTheCardTakesTheirBadges(): void
    {
        $saved = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             item("networks").querySelector("[value=linkedin]").checked = false;
             item("save-networks").click();
             const held = item("save-networks").disabled;
             await frame(); await frame();

             return { url: window.__sent.url, body: window.__sent.body, held, badges: null !== item("draft").querySelector("[data-name=badges]"), icon: null !== item("draft").querySelector("[data-name=icon]") };'
        );

        $this->assertSame('/management/social-calendar/networks', $saved['url'], 'The networks were not saved where the screen said to save them.');
        $this->assertSame('networks%5B%5D=bluesky&post=7', $saved['body'], 'What was sent is not the networks left ticked.');
        $this->assertTrue($saved['held'], 'The button was not held while the texts were written.');
        $this->assertTrue($saved['badges'], 'The card did not take the badges the panel answered with.');
        $this->assertFalse($saved['icon'], 'The card kept the badges it had.');
    }

    // A badge clicked on the series' page saves the networks then ticked for its post at once, under the screen's token, its toggles held meanwhile - no panel asked for in answer
    public function testANetworkTickedOnTheSeriesPageIsSavedAtOnce(): void
    {
        $saved = $this->calendar(
            'document.querySelector("[data-controller=social-calendar]").insertAdjacentHTML("beforeend", \'<form data-name="toggles" data-action="change->social-calendar#toggleNetworks" data-post-id="9"><fieldset><input type="checkbox" name="networks[]" value="bluesky" checked><input data-name="linkedin" type="checkbox" name="networks[]" value="linkedin"></fieldset></form>\');
             await frame(); await frame();
             item("linkedin").checked = true;
             item("linkedin").dispatchEvent(new Event("change", { bubbles: true }));
             const held = item("toggles").querySelector("fieldset").disabled;
             await frame(); await frame();

             return { url: window.__sent.url, body: window.__sent.body, token: window.__sent.token, held };'
        );

        $this->assertSame('/management/social-calendar/networks', $saved['url'], 'The networks were not saved where the screen said to save them.');
        $this->assertSame('networks%5B%5D=bluesky&networks%5B%5D=linkedin&post=9&bare=1', $saved['body'], 'What was sent is not the networks ticked for the post, no panel asked for.');
        $this->assertSame('jeton', $saved['token'], 'The networks were sent without the screen\'s token.');
        $this->assertTrue($saved['held'], 'The toggles were not held while the networks were saved.');
    }

    // A badge the server refuses on the series' page says its reason, or the screen's own words when it gave none
    public function testANetworkRefusedOnTheSeriesPageSaysWhy(): void
    {
        $alerts = $this->calendar(
            'const alerts = [];
             window.alert = (message) => alerts.push(message);
             document.querySelector("[data-controller=social-calendar]").insertAdjacentHTML("beforeend", \'<form data-name="refused" data-action="change->social-calendar#toggleNetworks" data-post-id="9"><fieldset><input data-name="refused-linkedin" type="checkbox" name="networks[]" value="linkedin"></fieldset></form>\');
             await frame(); await frame();
             window.__refusal = "Linkedin : refusé";
             item("refused-linkedin").dispatchEvent(new Event("change", { bubbles: true }));
             await frame(); await frame();
             window.__refusal = "";
             item("refused").querySelector("fieldset").disabled = false;
             item("refused-linkedin").dispatchEvent(new Event("change", { bubbles: true }));
             await frame(); await frame();
             window.__refusal = undefined;

             return alerts;'
        );

        $this->assertSame(['Linkedin : refusé', 'Echec'], $alerts, 'A refused network did not say why.');
    }

    // The duplicate is asked for the post, its button held meanwhile
    public function testThePostIsDuplicated(): void
    {
        $sent = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             window.alert = () => {};
             window.__refusal = "";
             item("duplicate").click();
             const held = item("duplicate").disabled;
             await frame(); await frame();

             return { url: window.__sent.url, body: window.__sent.body, held };'
        );

        $this->assertSame(['/management/social-calendar/duplicate', 'post=7', true], [$sent['url'], $sent['body'], $sent['held']], 'The duplicate was not asked for the post, or its button was not held.');
    }

    // Files held over the panel's zone light it, and those let go on it are sent with the post under the screen's token, the zone held and saying so while they go
    public function testFilesDroppedOnThePanelAreSent(): void
    {
        $sent = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             let upload = null;
             window.fetch = (url, options) => {
                 upload = { url, post: options.body.get("post"), names: options.body.getAll("files[]").map((file) => file.name), token: options.headers["X-CSRF-Token"] };

                 return new Promise(() => {});
             };
             const files = new DataTransfer();
             files.items.add(new File(["a"], "photo.jpg", { type: "image/jpeg" }));
             files.items.add(new File(["b"], "reel.mp4", { type: "video/mp4" }));
             const zone = item("drop");
             const hold = (type) => zone.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: files }));
             hold("dragenter");
             const lit = zone.classList.contains("is-over");
             const kept = !hold("drop");
             await frame();

             return { lit, kept, upload, busy: zone.classList.contains("is-busy"), label: zone.querySelector(".social-calendar-panel-drop-label").textContent, out: !zone.classList.contains("is-over") };'
        );

        $this->assertTrue($sent['lit'], 'The zone was not lit while files were held over it.');
        $this->assertTrue($sent['kept'], 'The browser was left to open the files dropped in its tab.');
        $this->assertSame(['/management/social-calendar/upload', '7', ['photo.jpg', 'reel.mp4'], 'jeton'], [$sent['upload']['url'], $sent['upload']['post'], $sent['upload']['names'], $sent['upload']['token']], 'The files were not sent with the post, where the screen said, under its token.');
        $this->assertSame([true, 'Envoi', true], [$sent['busy'], $sent['label'], $sent['out']], 'The zone was not held, saying so, while the files went.');
    }

    // Escape closes the panel, the calendar taking its whole width back
    public function testEscapeClosesThePanel(): void
    {
        $closed = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             const narrowed = root.firstElementChild.classList.contains("social-calendar-has-panel");
             window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));

             return { narrowed, hidden: item("panel").hidden, wide: !root.firstElementChild.classList.contains("social-calendar-has-panel") };'
        );

        $this->assertSame(['narrowed' => true, 'hidden' => true, 'wide' => true], $closed, 'Escape did not close the panel, or the calendar did not narrow beside it.');
    }

    // Escape typed in one of the panel's fields leaves it open, what was typed there kept
    public function testEscapeInAFieldKeepsThePanel(): void
    {
        $open = $this->calendar(
            'item("draft").click(); await frame(); await frame();
             item("panel").querySelector("input").dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));

             return !item("panel").hidden;'
        );

        $this->assertTrue($open, 'Escape in a field of the panel closed it.');
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

                            return Promise.resolve({ ok: true, text: () => Promise.resolve(\'<input type="datetime-local" data-social-calendar-target="planAt" value="2026-10-05T14:20"><button data-name="plan" data-action="social-calendar#plan" data-post-id="7">Plan</button><button data-name="approve" data-action="social-calendar#place" data-post-id="7" data-to="approve">Approve</button><button data-name="unapprove" data-action="social-calendar#place" data-post-id="7" data-to="unapprove">Unapprove</button><button data-name="close" data-action="social-calendar#closePanel">Close</button><form data-name="networks" data-action="social-calendar#saveNetworks" data-post-id="7"><input type="checkbox" name="networks[]" value="bluesky" checked><input type="checkbox" name="networks[]" value="linkedin" checked><button data-name="save-networks" type="submit">Save</button></form><button data-name="duplicate" data-action="social-calendar#duplicate" data-post-id="7">Duplicate</button><ul class="medias"><li data-name="m1" data-social-calendar-target="media" data-post-id="7" data-media-id="1"></li><li data-name="m2" data-social-calendar-target="media" data-post-id="7" data-media-id="2"></li><li data-name="m3" data-social-calendar-target="media" data-post-id="7" data-media-id="3"></li></ul><button data-name="remove" data-action="social-calendar#removeMedia" data-post-id="7" data-media-id="2" data-confirm="Retirer ?">Remove</button><video data-name="playable" src="/v.mp4#t=1" data-action="click->social-calendar#zoom"></video><dialog data-name="picture"></dialog><label data-name="drop" data-social-calendar-target="drop" data-post-id="7" data-uploading="Envoi"><input type="file" multiple data-action="social-calendar#pickFiles"><span class="social-calendar-panel-drop-label">Déposez</span></label>\') });
                        }
                        window.__saves += 1;
                        window.__sent = { url, body: String(options.body), token: options.headers["X-CSRF-Token"] };

                        // A refusal when the scenario sets one, its reason as the body - the networks saved answering the badges of the card too
                        return Promise.resolve(undefined === window.__refusal ? { ok: true, text: () => Promise.resolve((String(url).endsWith("/networks") ? \'<template data-social-calendar-target="badges" data-post-id="7"><span class="social-network-badges" data-name="badges"></span></template>\' : "") + \'<div data-social-calendar-target="thumbnail" data-post-id="7" data-image="\' + (window.__image ?? "/social/3.jpg") + \'"></div>\') } : { ok: false, text: () => Promise.resolve(window.__refusal) });
                    };
                    document.addEventListener("social-calendar:saved", (event) => event.preventDefault());',
            ]
        );
    }

    // Two days of the month and two quarters of an hour of the week, as social_calendar.html.twig renders them
    private function page(): string
    {
        return '<div data-controller="social-calendar" data-action="keydown.esc@window->social-calendar#closePanel" data-social-calendar-panel-url-value="/management/social-calendar/post" data-social-calendar-url-value="' . self::URL . '" data-social-calendar-medias-url-value="/management/social-calendar/medias" data-social-calendar-upload-url-value="/management/social-calendar/upload" data-social-calendar-networks-url-value="/management/social-calendar/networks" data-social-calendar-duplicate-url-value="/management/social-calendar/duplicate" data-social-calendar-new-url-value="https://example.org/management/social-post/new" data-social-calendar-token-value="jeton" data-social-calendar-failed-label-value="Echec">
                <div data-name="d1004" data-social-calendar-target="zone" data-to="2026-10-04" data-action="dblclick->social-calendar#create">
                    <a data-name="draft" href="#" draggable="false" data-social-calendar-target="item" data-action="social-calendar#open" data-panel-id="7" data-post-id="7" data-at="2026-10-04T19:00:00+02:00" class="social-calendar-card is-draft"><img src="data:," alt="">Draft<span class="social-network-badges"><img data-name="icon" src="data:," alt=""></span></a>
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
                <dialog data-name="zoom" data-social-calendar-target="zoom" data-action="click->social-calendar#closeZoom close->social-calendar#clearZoom"></dialog>
            </div>';
    }
}
