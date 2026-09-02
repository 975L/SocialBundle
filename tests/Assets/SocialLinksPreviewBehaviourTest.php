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

// assets/js/social-links-preview.js, and assets/js/social-link-network-toggle.js beside it - the two halves of the social links screen
// The preview has one rule that is not in the form at all: "text" hides the icons through the stylesheet alone, so the label is all that is left and is forced on whatever the checkbox says - exactly as the real render does. A checkbox that says one thing and a preview that shows another is the whole failure this screen has to avoid
#[Group('browser')]
class SocialLinksPreviewBehaviourTest extends JsCase
{
    private const array STYLES = ['minimal', 'colored', 'outline', 'text'];

    public function testThePreviewCatchesUpWithTheSavedStyleOnLoad(): void
    {
        $loaded = $this->links('return { style: styles(), labels: labels() };');

        $this->assertSame(['social-links--outline'], $loaded['style'], 'The preview opens on the style the widget renders rather than on the one the site was saved with.');
        $this->assertSame([true, true], $loaded['labels'], 'The labels are shown on a site saved with none.');
    }

    public function testPickingAStyleTakesTheOneBeforeItOff(): void
    {
        $this->assertSame(['social-links--colored'], $this->links('pick("colored"); return styles();'), 'The style picked before is still on the list, so what is drawn depends on which of the two the stylesheet declares last.');
    }

    // The one rule the form itself never states
    public function testTheTextStyleShowsTheLabelsWhateverTheCheckboxSays(): void
    {
        $text = $this->links('pick("text"); return labels();');

        $this->assertSame([false, false], $text, 'The text style hides its labels, which leaves the list showing nothing at all - the icons being hidden by the stylesheet.');
    }

    public function testLeavingTheTextStyleGivesTheCheckboxItsSayBack(): void
    {
        $back = $this->links('pick("text"); const during = labels(); pick("minimal"); return { during, after: labels() };');

        $this->assertSame([false, false], $back['during']);
        $this->assertSame([true, true], $back['after'], 'The labels stayed on after leaving the style that forced them, over a site that shows none.');
    }

    public function testTheLabelsFollowTheirCheckboxOnEveryOtherStyle(): void
    {
        $followed = $this->links(
            'const box = root.querySelector("[data-social-links-display-label-checkbox]");
             box.checked = true;
             fire(box);
             const on = labels();
             box.checked = false;
             fire(box);

             return { on, off: labels() };'
        );

        $this->assertSame([false, false], $followed['on'], 'Ticking the box does not show the labels.');
        $this->assertSame([true, true], $followed['off'], 'Unticking the box does not hide the labels.');
    }

    // The custom label and icon only mean anything for an entry naming no network of its own
    public function testPickingANetworkPutsTheCustomFieldsAwayAndClearingItBringsThemBack(): void
    {
        $toggled = $this->links(
            'const select = root.querySelector("#premiere [data-social-link-network-select]");
             const custom = () => root.querySelector("#premiere [data-social-link-network-custom]").hidden;
             select.value = "facebook";
             fire(select);
             const named = custom();
             select.value = "";
             fire(select);

             return { named, cleared: custom(), other: root.querySelector("#seconde [data-social-link-network-custom]").hidden };'
        );

        $this->assertTrue($toggled['named'], 'An entry naming a network still offers a label and an icon of its own, which nothing would ever use.');
        $this->assertFalse($toggled['cleared'], 'An entry whose network was cleared has nowhere left to say what it is called.');
        $this->assertTrue($toggled['other'], 'Answering one entry moved the fields of another.');
    }

    // The entries are a collection, and one added after the page was drawn is delegated to like any other
    public function testAnEntryAddedAfterwardsIsAnsweredToo(): void
    {
        $this->assertTrue(
            (bool) $this->links(
                'root.querySelector("#seconde").insertAdjacentHTML("afterend", "<div class=\'accordion-body\' id=\'troisieme\'><select data-social-link-network-select><option value=\'\'></option><option value=\'mastodon\'>Mastodon</option></select><div data-social-link-network-custom></div></div>");
                 const select = root.querySelector("#troisieme [data-social-link-network-select]");
                 select.value = "mastodon";
                 fire(select);

                 return root.querySelector("#troisieme [data-social-link-network-custom]").hidden;'
            ),
            'An entry added after the page was drawn is not answered to, so its custom fields stay beside a network that names itself.'
        );
    }

    private function links(string $probe): mixed
    {
        $preamble = 'const list = () => root.querySelector("[data-social-links-preview-list]");
             const styles = () => [...list().classList].filter((name) => name.startsWith("social-links--"));
             const labels = () => [...list().querySelectorAll(".social-link-label")].map((label) => label.hidden);
             const fire = (el) => el.dispatchEvent(new Event("change", { bubbles: true }));
             const pick = (value) => { const select = root.querySelector("[data-social-links-icon-style-select]"); select.value = value; fire(select); };
             const frame = () => new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
             document.dispatchEvent(new Event("DOMContentLoaded"));
             await frame(); ';

        return $this->observe(
            $this->page(),
            [],
            $preamble . $probe,
            ['modules' => ['preview' => 'social-links-preview', 'toggle' => 'social-link-network-toggle']]
        );
    }

    // The preview widget, rendered at the default the form knows nothing better than, and two entries of the collection beside it
    private function page(): string
    {
        $options = '';
        foreach (self::STYLES as $style) {
            $options .= sprintf('<option value="%s"%s>%s</option>', $style, 'outline' === $style ? ' selected' : '', ucfirst($style));
        }

        return '<ul data-social-links-preview-list class="social-links social-links--minimal">
                <li><a href="#"><span class="social-link-label">Facebook</span></a></li>
                <li><a href="#"><span class="social-link-label">Mastodon</span></a></li>
            </ul>
            <form>
                <select data-social-links-icon-style-select>' . $options . '</select>
                <input type="checkbox" data-social-links-display-label-checkbox>
                <div class="accordion-body" id="premiere">
                    <select data-social-link-network-select><option value=""></option><option value="facebook">Facebook</option></select>
                    <div data-social-link-network-custom></div>
                </div>
                <div class="accordion-body" id="seconde">
                    <select data-social-link-network-select><option value=""></option><option value="mastodon" selected>Mastodon</option></select>
                    <div data-social-link-network-custom hidden></div>
                </div>
            </form>';
    }
}
