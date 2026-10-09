/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

import { Controller } from "@hotwired/stimulus";
import { addSortGesture } from "@c975l/ui-bundle/pointer-sort.js";

// The publications calendar (see management/social_calendar.html.twig): a card dragged onto a quarter of an hour of the week is planned at that moment, onto a day of the month at its own time that day, its approval untouched; a coming moment double-clicked writes a new post there. The gesture is UiBundle's (pointer-sort.js), this controller owning only where a card lands, saved on the spot then the page reloaded, the server computing where every other post falls
export default class extends Controller {
    static targets = ["item", "zone", "panel", "planAt", "group"];
    static values = { url: String, token: String, panelUrl: String, newUrl: String, failedLabel: String };

    // Laid at its quarter of an hour and in its lane from its data, a style attribute being refused by a Content-Security-Policy without 'unsafe-inline'
    groupTargetConnected(group) {
        for (const name of ["row", "lane", "lanes"]) {
            group.style.setProperty(`--${name}`, group.dataset[name]);
        }
    }

    // Armed per card as it appears; a plain click still opens the post, only a real drag being taken over
    itemTargetConnected(item) {
        addSortGesture(item, {
            item,
            onStart: () => this.pickUp(item),
            onMove: (dragged, x, y) => this.moveTo(dragged, x, y),
            onDrop: () => this.drop(item),
            onCancel: () => this.putBack(item),
        });
    }

    // Where the card was picked up: it never leaves it, following the pointer by a translation, so a week's grid keeps its layout under it
    pickUp(item) {
        this.origin = item.parentElement;
        this.start = null;
        this.over = null;
    }

    // Hit-tested through everything stacked under the pointer: the card dragged is out of hit-testing (pointer-sort.js), and the other cards laid over a week's quarters of an hour must not hide the quarter they stand on
    moveTo(item, x, y) {
        this.start ??= { x, y };
        item.style.transform = `translate(${x - this.start.x}px, ${y - this.start.y}px)`;

        const over = document.elementsFromPoint(x, y).find((element) => element.matches('[data-social-calendar-target~="zone"]')) ?? null;
        if (over === this.over) return;

        this.over?.classList.remove("is-over");
        this.over = over;
        over?.classList.add("is-over");
    }

    drop(item) {
        const zone = this.over;
        this.putBack(item);

        // A card put back where it was picked up, or on the quarter of an hour it already stands at, saves nothing
        if (!zone || zone === this.origin || zone.dataset.to === item.dataset.at) return;

        this.save(item.dataset.postId, zone.dataset.to);
    }

    putBack(item) {
        this.over?.classList.remove("is-over");
        this.over = null;
        item.style.transform = "";
    }

    // A card clicked opens its panel beside the calendar; a click with a modifier key keeps the link's own way, the post's screen in a new tab
    open(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button > 0) return;
        event.preventDefault();

        const url = new URL(this.panelUrlValue, window.location.href);
        url.searchParams.set("id", event.currentTarget.dataset.panelId);
        fetch(url)
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
            .then((html) => {
                this.panelTarget.innerHTML = html;
                this.panelTarget.hidden = false;
            })
            .catch(() => window.location.assign(event.currentTarget.href));
    }

    // A coming moment double-clicked opens the screen of a new post planned there - a card double-clicked on it keeps opening its own. Announced first, cancelable, as a save is
    create(event) {
        if (event.target.closest('[data-social-calendar-target~="item"], .social-calendar-card')) return;

        const url = new URL(this.newUrlValue, window.location.href);
        url.searchParams.set("at", event.currentTarget.dataset.to);
        if (!this.dispatch("create", { detail: { url: url.href }, cancelable: true }).defaultPrevented) window.location.assign(url);
    }

    closePanel() {
        this.panelTarget.hidden = true;
        this.panelTarget.innerHTML = "";
    }

    // The moment typed in the panel, sent as typed: the server reads it in its own time zone, the one the field shows, and brings it to the quarter of an hour
    plan(event) {
        const value = this.planAtTarget.value;
        if ("" === value) return;

        this.save(event.currentTarget.dataset.postId, value);
    }

    // The panel's other buttons: approving the post, or taking it back to a draft
    place(event) {
        this.save(event.currentTarget.dataset.postId, event.currentTarget.dataset.to);
    }

    // A refusal said, then the page reloaded either way: it shows what was actually saved, and where every other post now falls
    save(post, to) {
        fetch(this.urlValue, {
            method: "POST",
            headers: { "X-CSRF-Token": this.tokenValue },
            body: new URLSearchParams({ post, to }),
        })
            .then((response) => (response.ok ? this.saved(true) : response.text().then((reason) => this.saved(false, reason))))
            .catch(() => this.saved(false));
    }

    // Announced before the reload, cancelable, so a page refreshing its calendar on its own may take over - a refusal says the server's reason when it gave one
    saved(ok, reason = "") {
        if (!ok) window.alert("" !== reason ? reason : this.failedLabelValue);

        const event = this.dispatch("saved", { detail: { ok }, cancelable: true });
        if (!event.defaultPrevented) window.location.reload();
    }
}
