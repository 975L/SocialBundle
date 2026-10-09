/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Opens a share link in a small centered popup instead of a full navigation - migrated from c975L/ShareButtonsBundle's functions.js, scoped to "[data-controller=shareButtonsPopup]" via data-action instead of a global ".btn-share" listener
export default class extends Controller {
    // The address the server built the share links with, before a page script (a calculator writing its choices into it) moves it on
    connect() {
        this.renderedUrl = window.location.href;
    }

    open(event) {
        event.preventDefault();

        // The page's address as it stands now, so a shared estimate opens with the choices it was made with
        const href = event.currentTarget.href.split(encodeURIComponent(this.renderedUrl)).join(encodeURIComponent(window.location.href));

        const width = (screen.width * 50) / 100;
        const height = (screen.height * 40) / 100;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;

        window.open(
            href,
            "Share",
            `toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=no, resizable=no, copyhistory=no, width=${width}, height=${height}, top=${top}, left=${left}`
        );
    }

    // Copies the address the other buttons share - moved on with the page as open() does - and says so on the button for two seconds; offered to copy by hand where the clipboard is refused
    async copy(event) {
        event.preventDefault();
        const button = event.currentTarget;
        // Read once: a second click within the two seconds would otherwise keep "copied" for good
        button.dataset.label ??= button.title;
        const label = button.dataset.label;
        const url = button.href === this.renderedUrl ? window.location.href : button.href;

        try {
            await navigator.clipboard.writeText(url);
        } catch {
            window.prompt(label, url);

            return;
        }

        button.title = button.dataset.copiedLabel;
        button.setAttribute("aria-label", button.dataset.copiedLabel);
        button.classList.add("is-copied");
        setTimeout(() => {
            button.title = label;
            button.setAttribute("aria-label", label);
            button.classList.remove("is-copied");
        }, 2000);
    }
}
