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
    static targets = ["item", "zone", "panel", "planAt", "group", "media", "thumbnail", "badges", "drop", "zoom", "pick", "pickAll"];
    static values = { url: String, token: String, panelUrl: String, mediasUrl: String, uploadUrl: String, textsUrl: String, networksUrl: String, duplicateUrl: String, openId: Number, newUrl: String, failedLabel: String, textsFailedLabel: String };

    // A copy just made opens its panel with the page, its id then taken off the address so a reload does not open it again
    connect() {
        if (this.openIdValue <= 0) return;

        this.openPanel(this.openIdValue);
        const url = new URL(window.location.href);
        url.searchParams.delete("post");
        window.history.replaceState(window.history.state, "", url);
    }

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

        const href = event.currentTarget.href;
        this.openPanel(event.currentTarget.dataset.panelId, () => window.location.assign(href));
    }

    // The post's panel read and shown, the calendar narrowed beside it on a wide screen (see management.scss) - $failed run when it cannot be read
    openPanel(id, failed = () => {}) {
        const url = new URL(this.panelUrlValue, window.location.href);
        url.searchParams.set("id", id);
        fetch(url)
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
            .then((html) => {
                this.panelTarget.innerHTML = html;
                this.panelTarget.hidden = false;
                this.element.classList.add("social-calendar-has-panel");
            })
            .catch(failed);
    }

    // A coming moment double-clicked opens the screen of a new post planned there - a card double-clicked on it keeps opening its own. Announced first, cancelable, as a save is
    create(event) {
        if (event.target.closest('[data-social-calendar-target~="item"], .social-calendar-card')) return;

        const url = new URL(this.newUrlValue, window.location.href);
        url.searchParams.set("at", event.currentTarget.dataset.to);
        if (!this.dispatch("create", { detail: { url: url.href }, cancelable: true }).defaultPrevented) window.location.assign(url);
    }

    // Closed by its button or Escape, the calendar taking its whole width back - an Escape typed in a field only closing what the browser opened there, so its unsaved text stays
    closePanel(event) {
        if (event?.type === "keydown" && event.target.closest?.("input, textarea, select")) return;
        // The escape key closes the picture or the video shown over the panel first, the dialog doing it on its own - UiBundle's for a picture, this controller's for a video
        if (event?.type === "keydown" && this.element.querySelector("dialog[open]")) return;
        this.panelTarget.hidden = true;
        this.panelTarget.innerHTML = "";
        this.element.classList.remove("social-calendar-has-panel");
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

    // Armed per media of the panel as it is read: dragged among the others, it takes the place of the one under the pointer - before it from its left half, after it from its right - the order saved once let go, and put back as it stood when the gesture is cancelled
    mediaTargetConnected(media) {
        addSortGesture(media, {
            item: media,
            ignore: "button",
            onStart: () => {
                this.mediaItems = [...media.parentElement.children];
                this.mediaOrder = this.mediaIds(media).join();
            },
            onMove: (dragged, x, y) => {
                const over = document.elementsFromPoint(x, y).find((element) => element !== dragged && element.parentElement === dragged.parentElement);
                if (!over) return;

                const box = over.getBoundingClientRect();
                over.parentElement.insertBefore(dragged, x < box.left + box.width / 2 ? over : over.nextSibling);
            },
            onDrop: () => {
                if (this.mediaIds(media).join() !== this.mediaOrder) this.saveMedias(media.dataset.postId, this.mediaIds(media));
            },
            onCancel: () => media.parentElement.append(...this.mediaItems),
        });

        // The link UiBundle wraps a picture in is natively draggable, and a native drag would cancel the gesture
        media.addEventListener("dragstart", (event) => event.preventDefault());
    }

    // A video of the panel played over the page with its sound and controls, from its start - the panel's own showing a moment in. Its pictures are UiBundle's lightbox (see _social_calendar_panel.html.twig)
    zoom(event) {
        event.preventDefault();
        const shown = document.createElement("video");
        shown.src = (event.currentTarget.currentSrc || event.currentTarget.src).split("#")[0];
        shown.controls = true;
        shown.autoplay = true;
        this.zoomTarget.replaceChildren(shown);
        this.zoomTarget.showModal();
    }

    // A click beside the video closes; a click on it is left to its controls
    closeZoom(event) {
        if ("VIDEO" === event.target.tagName) return;
        this.zoomTarget.close();
    }

    // Emptied once closed, however it was, so a video stops playing
    clearZoom() {
        this.zoomTarget.replaceChildren();
    }

    // The ids of the panel's medias, in the order they now stand
    mediaIds(media) {
        return [...media.parentElement.children].map((sibling) => sibling.dataset.mediaId);
    }

    // A media taken off the post, once confirmed: its file goes with it
    removeMedia(event) {
        const button = event.currentTarget;
        if (!window.confirm(button.dataset.confirm)) return;

        this.saveMedias(button.dataset.postId, [], button.dataset.mediaId);
    }

    // The order of the medias, or the one taken off, saved on the spot: the server answers the panel as it now reads, the calendar staying where it is
    saveMedias(post, medias, remove = "") {
        const body = new URLSearchParams({ post });
        if (remove) body.set("remove", remove);
        for (const id of medias) body.append("medias[]", id);

        this.postPanel(this.mediasUrlValue, body).catch(() => this.saved(false));
    }

    // A change of the panel sent with the page's token, the panel replaced by the one the server answers - a refusal rejected with its response, for the caller to say
    postPanel(url, body) {
        return fetch(url, { method: "POST", headers: { "X-CSRF-Token": this.tokenValue }, body })
            .then((response) => (response.ok ? response.text() : Promise.reject(response)))
            .then((html) => (this.panelTarget.innerHTML = html));
    }

    // The panel's form sent for its post, the button held while it goes
    panelForm(event) {
        event.preventDefault();
        const button = event.submitter;
        const body = new URLSearchParams(new FormData(event.currentTarget));
        body.set("post", event.currentTarget.dataset.postId);
        if (button) button.disabled = true;

        return [button, body];
    }

    // Armed per drop zone of the panel as it is read: lit while files are held over it, then the ones let go on it sent - the browser kept from opening them in its tab
    dropTargetConnected(zone) {
        const over = (event) => {
            event.preventDefault();
            zone.classList.add("is-over");
        };
        zone.addEventListener("dragenter", over);
        zone.addEventListener("dragover", over);
        zone.addEventListener("dragleave", (event) => {
            if (!zone.contains(event.relatedTarget)) zone.classList.remove("is-over");
        });
        zone.addEventListener("drop", (event) => {
            event.preventDefault();
            zone.classList.remove("is-over");
            this.upload(zone, event.dataTransfer.files);
        });
    }

    // The files picked by a click on the zone, sent the same way
    pickFiles(event) {
        this.upload(event.currentTarget.closest("[data-social-calendar-target~='drop']"), event.currentTarget.files);
    }

    // The files sent with the post's id, the zone held and saying so while they go - the server answering the panel with them added, or why it refused them
    upload(zone, files) {
        if (0 === files.length) return;

        const body = new FormData();
        body.set("post", zone.dataset.postId);
        for (const file of files) body.append("files[]", file);
        zone.classList.add("is-busy");
        const label = zone.querySelector(".social-calendar-panel-drop-label");
        if (label) label.textContent = zone.dataset.uploading;

        // Only a refusal of the files says its own reason, a file too heavy for the server answering a page of its own
        const refused = (reason = "") => {
            window.alert("" !== reason ? reason : this.failedLabelValue);
            this.openPanel(zone.dataset.postId);
        };
        this.postPanel(this.uploadUrlValue, body).catch((response) => (response instanceof Response && 422 === response.status ? response.text().then(refused) : refused()));
    }

    // The panel's texts saved together, the button held while they are sent, the server answering the panel as it now reads - a refusal said, the texts typed left as they are to be corrected
    saveTexts(event) {
        const [button, body] = this.panelForm(event);
        const refused = (reason = "") => {
            if (button) button.disabled = false;
            window.alert("" !== reason ? reason : this.textsFailedLabelValue);
        };
        this.postPanel(this.textsUrlValue, body).catch((response) => (response instanceof Response ? response.text().then(refused) : refused()));
    }

    // The networks ticked in the panel saved, the button held while their texts are written - which may take a few seconds - the server answering the panel as it now reads
    saveNetworks(event) {
        const [, body] = this.panelForm(event);
        this.postPanel(this.networksUrlValue, body).catch(() => this.saved(false));
    }

    // A network's badge clicked on the series' page (see social_calendar_series.html.twig): the networks then ticked saved at once, the page reloaded to show what was - no panel there to answer into, so none asked for; a refusal says its reason when the server gives one
    toggleNetworks(event) {
        const form = event.currentTarget;
        const body = new URLSearchParams(new FormData(form));
        body.set("post", form.dataset.postId);
        body.set("bare", "1");
        form.querySelector("fieldset").disabled = true;

        this.send(this.networksUrlValue, body);
    }

    // The series' page: every draft checked or unchecked at once
    pickAll(event) {
        for (const pick of this.pickTargets) pick.checked = event.currentTarget.checked;
    }

    // The series' page: "Select all" checked when every draft is, half-checked when only some are
    syncPicks() {
        if (!this.hasPickAllTarget) return;

        const checked = this.pickTargets.filter((pick) => pick.checked).length;
        this.pickAllTarget.checked = checked === this.pickTargets.length;
        this.pickAllTarget.indeterminate = checked > 0 && checked < this.pickTargets.length;
    }

    // The series' page: the drafts checked approved together, their series found from the first - nothing sent when none is
    approvePicked(event) {
        const picked = this.pickTargets.filter((pick) => pick.checked).map((pick) => pick.value);
        if (0 === picked.length) return;

        const body = new URLSearchParams({ post: picked[0], to: "approve-picked" });
        for (const id of picked) body.append("posts[]", id);
        event.currentTarget.disabled = true;

        this.send(this.urlValue, body);
    }

    // The post written again the day after, the calendar then opened on the copy's day with its panel
    duplicate(event) {
        const button = event.currentTarget;
        button.disabled = true;

        fetch(this.duplicateUrlValue, { method: "POST", headers: { "X-CSRF-Token": this.tokenValue }, body: new URLSearchParams({ post: button.dataset.postId }) })
            .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
            .then((url) => window.location.assign(url))
            .catch(() => this.saved(false));
    }

    // The panel read again says the post's networks as they now are, which its card on the calendar takes
    badgesTargetConnected(badges) {
        const card = this.element.querySelector(`[data-panel-id="${badges.dataset.postId}"]`);
        const current = card?.querySelector(":scope > .social-network-badges");
        const updated = badges.content.firstElementChild;
        if (current && updated) current.replaceWith(updated.cloneNode(true));
    }

    // The panel read again says the post's picture as it now is, which its card on the calendar takes: changed, gained when it had none, or lost with the last media
    thumbnailTargetConnected(thumbnail) {
        const card = this.element.querySelector(`[data-panel-id="${thumbnail.dataset.postId}"]`);
        if (!card) return;

        // The card's own picture, not the icon of one of its networks
        const picture = card.querySelector(":scope > img");
        if ("" === thumbnail.dataset.image) return picture?.remove();

        if (picture) {
            picture.src = thumbnail.dataset.image;
            return;
        }

        const created = document.createElement("img");
        created.alt = "";
        created.loading = "lazy";
        created.src = thumbnail.dataset.image;
        card.prepend(created);
    }

    // A refusal said, then the page reloaded either way: it shows what was actually saved, and where every other post now falls
    save(post, to) {
        this.send(this.urlValue, new URLSearchParams({ post, to }));
    }

    // The body sent under the screen's token, then saved() told how it went - with the server's reason for a refusal
    send(url, body) {
        fetch(url, { method: "POST", headers: { "X-CSRF-Token": this.tokenValue }, body })
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
