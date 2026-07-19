/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Keeps the "share_buttons_settings" preview (see share_buttons_style_preview_theme.html.twig) in sync with the "style" <select> and the "networks" checkboxes - both the style class and the shown/hidden, ordered set of buttons. The preview widget renders every known network, always starting on "distinct" (it has no access to the entity's saved values - see ShareButtonsStylePreviewType/ShareButtonsSettingsType), so this runs once on load too, not just on "change", to match their actual, server-rendered initial state. Also re-run after a drag reorder (see share-buttons-networks-sort.js), which changes the checkboxes' DOM order without firing "change" on any of them.
const STYLES = ['distinct', 'ellipse', 'circle', 'square', 'rounded', 'outline', 'minimal'];

function syncPreview() {
    const preview = document.querySelector('#ss-style-preview .social-share');
    if (!preview) return;

    const styleSelect = document.querySelector('[data-share-style-select]');
    if (styleSelect) {
        STYLES.forEach(style => preview.classList.remove(`social-share--${style}`));
        preview.classList.add(`social-share--${styleSelect.value}`);
    }

    const checkboxes = document.querySelectorAll('[data-share-networks-sortable] input[type="checkbox"]');
    checkboxes.forEach((checkbox, index) => {
        const button = preview.querySelector(`.social-share-btn--${checkbox.value}`);
        if (!button) return;

        button.hidden = !checkbox.checked;
        button.style.order = index;
    });
}

document.addEventListener('DOMContentLoaded', syncPreview);

document.addEventListener('change', event => {
    if (event.target.matches('[data-share-style-select]')
        || event.target.matches('[data-share-networks-sortable] input[type="checkbox"]')) {
        syncPreview();
    }
});

document.addEventListener('share-buttons-networks:reordered', syncPreview);
