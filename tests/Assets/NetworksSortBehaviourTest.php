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

// assets/js/share-buttons-networks-sort.js dragged for real, in a browser that lays the list out: no hidden order field, the networks are saved in the order their checkboxes sit in the document, so what is checked is that the form leaves in the new order - getBoundingClientRect deciding it, which an emulated DOM answers zero to
#[Group('browser')]
class NetworksSortBehaviourTest extends JsCase
{
    // The list laid out, each item tall enough for a midpoint to mean something
    private const string CSS = '
        [data-share-networks-sortable] { list-style: none; margin: 0; padding: 0; }
        .ss-networks-sortable-item { display: block; height: 40px; line-height: 40px; }
    ';

    // The checkbox and its label have to stay clickable, so only the handle starts a drag
    public function testAPressOnTheLabelDragsNothing(): void
    {
        $this->assertSame(['facebook', 'x', 'linkedin'], $this->list('await drag(2, 0, "label"); return submitted();'), 'Pressing a label and moving the pointer reordered the list, so a box can no longer be ticked.');
    }

    // A gesture cancelled mid-drag puts the item back where it was
    public function testACancelledDragPutsTheItemBack(): void
    {
        $this->assertSame(['facebook', 'x', 'linkedin'], $this->list('await drag(2, 0, null, true); return submitted();'), 'A cancelled drag left the item where it had been dragged to.');
    }

    // What the whole file exists for, and the only thing the server ever sees of it
    public function testTheOrderTheFormLeavesWithIsTheOrderTheItemsWereDroppedIn(): void
    {
        $dropped = $this->list(
            'const before = submitted();
             await drag(2, 0);

             return { before, after: submitted(), order: items().map((item) => item.querySelector("input").value) };'
        );

        $this->assertSame(['facebook', 'x', 'linkedin'], $dropped['before'], 'The list does not leave with the networks in the order it shows them.');
        $this->assertSame(['linkedin', 'facebook', 'x'], $dropped['after'], 'The reordered list leaves with the old order, so the drag changes nothing the server can see.');
        $this->assertSame($dropped['after'], $dropped['order'], 'What is shown and what is submitted disagree.');
    }

    // Dropped below the last one rather than above another, the branch that appends instead of inserting
    public function testAnItemDroppedBelowTheLastOneGoesToTheEnd(): void
    {
        $this->assertSame(
            ['x', 'linkedin', 'facebook'],
            $this->list('await drag(0, null); return submitted();'),
            'An item dragged past the last one did not go to the end of the list.'
        );
    }

    // The preview has no other way of knowing: a drag reorders the checkboxes without firing "change" on any of them
    public function testTheDropIsAnnouncedToWhateverIsShowingTheList(): void
    {
        $announced = $this->list(
            'let heard = 0;
             const listen = () => { heard += 1; };
             document.addEventListener("share-buttons-networks:reordered", listen);
             await drag(2, 0);
             document.removeEventListener("share-buttons-networks:reordered", listen);
             const item = items()[0];

             return { heard, dragging: item.classList.contains("ss-dragging") };'
        );

        $this->assertSame(1, $announced['heard'], 'A drop is announced to nobody, so the preview goes on showing the order the page was rendered with.');
        $this->assertFalse($announced['dragging'], 'The item that was dropped is still drawn as being dragged.');
    }

    private function list(string $probe): mixed
    {
        $preamble = 'const container = () => root.querySelector("[data-share-networks-sortable]");
             const items = () => [...container().querySelectorAll(".ss-networks-sortable-item")];
             const submitted = () => [...new FormData(root.querySelector("form")).getAll("networks[]")];
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             const point = (el, type, x, y) => el.dispatchEvent(new PointerEvent(type, { bubbles: true, cancelable: true, pointerId: 1, pointerType: "mouse", button: 0, buttons: 1, clientX: x, clientY: y }));
             // A drag as a mouse makes one: pressed on the handle (or on what "on" names), moved above the midpoint of the item dropped on - or past the last one when there is none - then let go
             const drag = async (from, to, on, cancelled) => {
                 const grip = items()[from].querySelector(on ?? ".ss-drag-handle");
                 const start = grip.getBoundingClientRect();
                 point(grip, "pointerdown", start.left + 2, start.top + 2);
                 const box = null === to ? container().getBoundingClientRect() : items()[to].getBoundingClientRect();
                 const y = null === to ? box.bottom + 10 : box.top + 1;
                 point(document, "pointermove", start.left + 2, y);
                 await frame();
                 point(document, cancelled ? "pointercancel" : "pointerup", start.left + 2, y);
                 await frame();
             };
             // The wiring runs on the document being ready, which it long since is on the page this suite shares
             document.dispatchEvent(new Event("DOMContentLoaded"));
             await frame(); ';

        return $this->observe($this->page(), [], $preamble . $probe, ['modules' => ['sort' => 'share-buttons-networks-sort'], 'css' => self::CSS]);
    }

    // The widget as share_buttons_style_preview_theme.html.twig renders it: a plain expanded ChoiceType, no position subfield anywhere
    private function page(): string
    {
        $items = '';
        foreach (['facebook', 'x', 'linkedin'] as $network) {
            $items .= sprintf(
                '<li class="ss-networks-sortable-item"><span class="ss-drag-handle">::</span><label><input type="checkbox" name="networks[]" value="%s" checked> %s</label></li>',
                $network,
                ucfirst($network)
            );
        }

        return '<form><ul data-share-networks-sortable>' . $items . '</ul></form>';
    }
}
