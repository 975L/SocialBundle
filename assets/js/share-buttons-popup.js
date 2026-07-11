/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
import { Controller } from "@hotwired/stimulus";

// Opens a share link in a small centered popup instead of a full navigation - migrated from
// c975L/ShareButtonsBundle's functions.js, scoped to "[data-controller=shareButtonsPopup]" via
// data-action instead of a global ".btn-share" listener
export default class extends Controller {
    open(event) {
        event.preventDefault();

        const width = (screen.width * 50) / 100;
        const height = (screen.height * 40) / 100;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;

        window.open(
            event.currentTarget.href,
            "Share",
            `toolbar=no, location=no, directories=no, status=no, menubar=no, scrollbars=no, resizable=no, copyhistory=no, width=${width}, height=${height}, top=${top}, left=${left}`
        );
    }
}
