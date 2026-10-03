/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

import { addSortGesture } from "@c975l/ui-bundle/pointer-sort.js";

// Drag-and-drop reordering for the "networks" checkbox list (see share_buttons_style_preview_theme.html.twig's "share_buttons_networks_widget" block). The gesture is UiBundle's (pointer-sort.js, mouse and finger alike, where native HTML5 drag and drop never fires at the finger), this file owning only where an item lands. No hidden order field either: reordering the <li>s also reorders their checkbox <input>s, and plain form submission serializes same-name fields in DOM order - ShareButtonsSettingsType reads that order straight back off the submitted "networks" array.
document.addEventListener("DOMContentLoaded", () => {
    const container = document.querySelector("[data-share-networks-sortable]");
    if (!container) return;

    // Only the handle starts a drag - the checkbox/label inside each item must stay clickable
    container.querySelectorAll(".ss-networks-sortable-item").forEach((item) => {
        const handle = item.querySelector(".ss-drag-handle");
        if (!handle) return;

        let origin = null;
        addSortGesture(handle, {
            item,
            onStart: () => {
                origin = item.nextElementSibling;
                item.classList.add("ss-dragging");
            },
            onMove: (dragged, x, y) => {
                const after = dragAfter(container, y);
                if (!after) container.appendChild(dragged);
                else container.insertBefore(dragged, after);
            },
            onDrop: () => {
                item.classList.remove("ss-dragging");
                document.dispatchEvent(new CustomEvent("share-buttons-networks:reordered"));
            },
            // insertBefore() with a null reference appends, which is where an item dragged from the last place belongs
            onCancel: () => {
                item.classList.remove("ss-dragging");
                container.insertBefore(item, origin);
            },
        });
    });
});

// The item the dragged one goes before: the first whose midpoint lies below the pointer, none past the last one
function dragAfter(container, y) {
    const items = [...container.querySelectorAll(".ss-networks-sortable-item:not(.ss-dragging)")];

    return items.reduce((closest, item) => {
        const box = item.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return { offset, element: item };
        return closest;
    }, { offset: -Infinity }).element;
}
