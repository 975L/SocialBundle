/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Native HTML5 drag-and-drop reordering for the "networks" checkbox list (see
// share_buttons_style_preview_theme.html.twig's "share_buttons_networks_widget" block) - adapted from
// c975L/UiBundle's assets/js/ea-sortable.js, which targets EasyAdmin CollectionType items with a
// "position" subfield and doesn't apply here (this is a plain expanded ChoiceType, no such subfield).
// No hidden order field either: reordering the <li>s also reorders their checkbox <input>s, and plain
// form submission serializes same-name fields in DOM order - ShareButtonsSettingsType reads that order
// straight back off the submitted "networks" array.
document.addEventListener('DOMContentLoaded', () => {
    const container = document.querySelector('[data-share-networks-sortable]');
    if (!container) return;

    let dragging = null;

    // Only the handle starts a drag - the checkbox/label inside each item must stay clickable
    container.querySelectorAll('.ss-networks-sortable-item').forEach(item => {
        const handle = item.querySelector('.ss-drag-handle');
        if (!handle) return;

        handle.addEventListener('mousedown', () => item.setAttribute('draggable', 'true'));
        handle.addEventListener('mouseup', () => item.removeAttribute('draggable'));
    });

    container.addEventListener('dragstart', event => {
        const item = event.target.closest('.ss-networks-sortable-item');
        if (!item) {
            event.preventDefault();
            return;
        }

        dragging = item;
        requestAnimationFrame(() => item.classList.add('ss-dragging'));
    });

    container.addEventListener('dragend', () => {
        if (!dragging) return;

        dragging.classList.remove('ss-dragging');
        dragging.removeAttribute('draggable');
        dragging = null;

        document.dispatchEvent(new CustomEvent('share-buttons-networks:reordered'));
    });

    container.addEventListener('dragover', event => {
        event.preventDefault();
        if (!dragging) return;

        const after = dragAfter(container, event.clientY);
        if (!after) container.appendChild(dragging);
        else container.insertBefore(dragging, after);
    });
});

function dragAfter(container, y) {
    const items = [...container.querySelectorAll('.ss-networks-sortable-item:not(.ss-dragging)')];

    return items.reduce((closest, item) => {
        const box = item.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return { offset, element: item };
        return closest;
    }, { offset: -Infinity }).element;
}
