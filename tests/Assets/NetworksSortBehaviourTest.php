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

// assets/js/share-buttons-networks-sort.js dragged for real, in a browser that lays the list out
// There is no hidden order field anywhere: the order the settings are saved in is the order the checkboxes sit in the document, plain form submission serializing same-name fields as they stand. So what has to be checked is not that a class moved but that the form now leaves with the networks in the new order - and the reordering itself is decided by getBoundingClientRect, which an emulated DOM answers zero to, putting every item in the same place as every other
#[Group('browser')]
class NetworksSortBehaviourTest extends JsCase
{
    // The list laid out, each item tall enough for a midpoint to mean something
    private const string CSS = '
        [data-share-networks-sortable] { list-style: none; margin: 0; padding: 0; }
        .ss-networks-sortable-item { display: block; height: 40px; line-height: 40px; }
    ';

    // The checkbox and its label have to stay clickable, so nothing is draggable until the handle is held
    public function testAnItemIsOnlyDraggableWhileItsHandleIsHeld(): void
    {
        $held = $this->list(
            'const item = items()[0];
             const before = item.getAttribute("draggable");
             fire(item.querySelector(".ss-drag-handle"), "mousedown");
             const during = item.getAttribute("draggable");
             fire(item.querySelector(".ss-drag-handle"), "mouseup");

             return { before, during, after: item.getAttribute("draggable") };'
        );

        $this->assertNull($held['before'], 'An item is draggable before anybody took hold of its handle, so ticking its checkbox starts a drag.');
        $this->assertSame('true', $held['during'], 'Holding the handle does not make the item draggable, and nothing can be reordered at all.');
        $this->assertNull($held['after'], 'The item stays draggable after the handle was let go.');
    }

    // A drag started anywhere but on an item is refused rather than carried out on nothing
    public function testADragStartedBesideAnItemIsRefused(): void
    {
        $this->assertTrue(
            (bool) $this->list('const event = new DragEvent("dragstart", { bubbles: true, cancelable: true }); container().dispatchEvent(event); return event.defaultPrevented;'),
            'A drag started on the list itself is carried out, dragging nothing.'
        );
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

             return { heard, dragging: item.classList.contains("ss-dragging"), draggable: item.getAttribute("draggable") };'
        );

        $this->assertSame(1, $announced['heard'], 'A drop is announced to nobody, so the preview goes on showing the order the page was rendered with.');
        $this->assertFalse($announced['dragging'], 'The item that was dropped is still drawn as being dragged.');
        $this->assertNull($announced['draggable'], 'The item that was dropped stays draggable, and its checkbox is no longer clickable.');
    }

    private function list(string $probe): mixed
    {
        $preamble = 'const container = () => root.querySelector("[data-share-networks-sortable]");
             const items = () => [...container().querySelectorAll(".ss-networks-sortable-item")];
             const submitted = () => [...new FormData(root.querySelector("form")).getAll("networks[]")];
             const fire = (el, type) => el.dispatchEvent(new Event(type, { bubbles: true }));
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             // A drag as the browser runs one: the handle held, the item picked up, moved over the place it is dropped at, and let go
             const drag = async (from, to) => {
                 const item = items()[from];
                 fire(item.querySelector(".ss-drag-handle"), "mousedown");
                 item.dispatchEvent(new DragEvent("dragstart", { bubbles: true, cancelable: true }));
                 await frame();
                 // Above the midpoint of the item dropped on, or past the last one when there is none to drop above
                 const box = null === to ? container().getBoundingClientRect() : items()[to].getBoundingClientRect();
                 container().dispatchEvent(new DragEvent("dragover", { bubbles: true, cancelable: true, clientY: null === to ? box.bottom + 10 : box.top + 1 }));
                 container().dispatchEvent(new DragEvent("dragend", { bubbles: true }));
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
