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

// assets/js/share-buttons-preview.js run over the settings form and the preview beside it
// A preview is only worth anything if it shows what the site will actually show, and every one of the ways it could quietly stop doing so is a matter of state rather than of code: a variant class left stacked under the one just picked, a button hidden by a checkbox nobody unticked, an order read from the page it was rendered with rather than from the list as it now stands
#[Group('browser')]
class ShareButtonsPreviewBehaviourTest extends JsCase
{
    // The widget renders every known network at the default shape and fill, so the first thing this has to do is catch up with the saved values
    public function testThePreviewCatchesUpWithTheSavedValuesOnLoad(): void
    {
        $loaded = $this->preview('return { classes: [...preview().classList], intro: intro().hidden, shown: buttons() };');

        $this->assertContains('social-share--shape-square', $loaded['classes'], 'The preview opens on the default shape rather than on the one the site was saved with.');
        $this->assertContains('social-share--fill-outline', $loaded['classes'], 'The preview opens on the default fill rather than on the one the site was saved with.');
        $this->assertFalse($loaded['intro'], 'The invitation line is hidden on a site that shows one.');
        $this->assertSame(['facebook:0', 'x:1', 'linkedin:2'], $loaded['shown'], 'The preview does not open on the networks the site was saved with, in the order it was saved with.');
    }

    // Read off the select's own options rather than a list restated in this file: a value added to ShareButtonsService and left out of that copy would never be removed again
    public function testPickingAShapeTakesTheOneBeforeItOffRatherThanStackingOverIt(): void
    {
        $picked = $this->preview('pick("[data-share-shape-select]", "round"); return [...preview().classList].filter((name) => name.startsWith("social-share--shape-"));');

        $this->assertSame(['social-share--shape-round'], $picked, 'The shape picked before is still on the preview, so what is drawn depends on which of the two the stylesheet happens to declare last.');
    }

    // "transparent" carries no colour of its own, and on the dashboard's light background it would show as nothing at all
    public function testATransparentFillIsGivenTheBandARealPagePaints(): void
    {
        $transparent = $this->preview(
            'pick("[data-share-fill-select]", "transparent");
             const backdrop = preview().classList.contains("social-share--preview-backdrop");
             pick("[data-share-fill-select]", "solid");

             return { backdrop, after: preview().classList.contains("social-share--preview-backdrop") };'
        );

        $this->assertTrue($transparent['backdrop'], 'Buttons with no fill of their own are previewed on the dashboard\'s own background, where they show as nothing at all.');
        $this->assertFalse($transparent['after'], 'The band stayed under a fill that paints itself.');
    }

    public function testTheInvitationLineFollowsItsCheckbox(): void
    {
        $toggled = $this->preview(
            'const box = root.querySelector("[data-share-display-intro-checkbox]");
             box.checked = false;
             fire(box);
             const off = intro().hidden;
             box.checked = true;
             fire(box);

             return { off, on: intro().hidden };'
        );

        $this->assertTrue($toggled['off'], 'The invitation line stays in the preview of a site that shows none.');
        $this->assertFalse($toggled['on'], 'The invitation line never comes back.');
    }

    public function testUntickingANetworkTakesItsButtonOutAndLeavesTheOthersWhereTheyWere(): void
    {
        $shown = $this->preview(
            'const box = root.querySelector("[value=x]");
             box.checked = false;
             fire(box);

             return buttons();'
        );

        $this->assertSame(['facebook:0', 'linkedin:2'], $shown, 'A network nobody shares to is still previewed, or taking it out moved the ones that stay.');
    }

    // A drag reorders the checkboxes without firing "change" on any of them, which is why the reorder is announced at all
    public function testTheOrderIsReadBackFromTheListAfterADrop(): void
    {
        $reordered = $this->preview(
            'const list = root.querySelector("[data-share-networks-sortable]");
             list.prepend(list.lastElementChild);
             const stale = buttons();
             document.dispatchEvent(new CustomEvent("share-buttons-networks:reordered"));

             return { stale, after: buttons() };'
        );

        $this->assertSame(['facebook:0', 'x:1', 'linkedin:2'], $reordered['stale'], 'The preview followed a reorder nobody announced, which says nothing about the announcement.');
        $this->assertSame(['linkedin:0', 'facebook:1', 'x:2'], $reordered['after'], 'The preview goes on showing the order the page was rendered with, where the form will save the new one.');
    }

    // The preview is one widget among several on the settings screen, and the rest of the form must not be listened to on its behalf
    public function testAChangeElsewhereOnTheFormLeavesThePreviewAlone(): void
    {
        $this->assertSame(
            ['facebook:0', 'x:1', 'linkedin:2'],
            $this->preview('const other = root.querySelector("#autre"); other.value = "quelque chose"; fire(other); return buttons();'),
            'Any change anywhere on the settings form redraws the preview.'
        );
    }

    private function preview(string $probe): mixed
    {
        $preamble = 'const preview = () => root.querySelector("#ss-style-preview .social-share");
             const intro = () => preview().querySelector(".social-share-intro");
             const fire = (el) => el.dispatchEvent(new Event("change", { bubbles: true }));
             const pick = (selector, value) => { const select = root.querySelector(selector); select.value = value; fire(select); };
             // Read in the order the visitor sees them rather than in the order they were rendered: the preview never moves a button, it renumbers them
             const buttons = () => [...preview().querySelectorAll(".social-share-btn")]
                 .filter((button) => !button.hidden)
                 .sort((a, b) => Number(a.style.order) - Number(b.style.order))
                 .map((button) => button.className.replace(/.*social-share-btn--/, "") + ":" + button.style.order);
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             document.dispatchEvent(new Event("DOMContentLoaded"));
             await frame(); ';

        return $this->observe($this->page(), [], $preamble . $probe, ['modules' => ['preview' => 'share-buttons-preview']]);
    }

    // The settings form and the preview widget beside it, the preview rendered at the defaults the widget knows nothing better than
    private function page(): string
    {
        $items = '';
        foreach (['facebook', 'x', 'linkedin'] as $network) {
            $items .= sprintf('<li class="ss-networks-sortable-item"><input type="checkbox" name="networks[]" value="%s" checked></li>', $network);
        }

        return '<div id="ss-style-preview">
                <div class="social-share social-share--shape-round social-share--fill-solid">
                    <p class="social-share-intro">Partagez cette page</p>
                    <a class="social-share-btn social-share-btn--facebook"></a>
                    <a class="social-share-btn social-share-btn--x"></a>
                    <a class="social-share-btn social-share-btn--linkedin"></a>
                </div>
            </div>
            <form>
                <select data-share-shape-select><option value="round">Rond</option><option value="square" selected>Carre</option></select>
                <select data-share-fill-select><option value="solid">Plein</option><option value="outline" selected>Contour</option><option value="transparent">Transparent</option></select>
                <input type="checkbox" data-share-display-intro-checkbox checked>
                <input type="text" id="autre" value="">
                <ul data-share-networks-sortable>' . $items . '</ul>
            </form>';
    }
}
