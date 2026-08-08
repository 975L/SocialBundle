/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Keeps the "share_buttons_settings" preview (see share_buttons_style_preview_theme.html.twig) in sync with the "shape" and "fill" <select>s, the "display intro" checkbox and the "networks" checkboxes - variant classes, invitation line and the shown/hidden, ordered set of buttons. The preview widget renders every known network, always at the default shape/fill (it has no access to the entity's saved values - see ShareButtonsStylePreviewType/ShareButtonsSettingsType), so this runs once on load too, not just on "change", to match their actual, server-rendered initial state. Also re-run after a drag reorder (see share-buttons-networks-sort.js), which changes the checkboxes' DOM order without firing "change" on any of them.
const VARIANTS = [
    { selector: '[data-share-shape-select]', prefix: 'social-share--shape-' },
    { selector: '[data-share-fill-select]', prefix: 'social-share--fill-' },
];

function syncPreview() {
    const preview = document.querySelector('#ss-style-preview .social-share');
    if (!preview) return;

    VARIANTS.forEach(({ selector, prefix }) => {
        const select = document.querySelector(selector);
        if (!select) return;

        // Read off the <select>'s own options rather than a list restated here: a value added to ShareButtonsService and left out of that copy would never be removed again, staying stacked under whatever is picked next
        Array.from(select.options).forEach(option => preview.classList.remove(`${prefix}${option.value}`));
        preview.classList.add(`${prefix}${select.value}`);
    });

    // "transparent" carries no color of its own: on the dashboard's light background it shows as nothing at all without a band standing in for the one a real page paints (see sass/_share-buttons.scss)
    preview.classList.toggle(
        'social-share--preview-backdrop',
        preview.classList.contains('social-share--fill-transparent')
    );

    const displayIntroCheckbox = document.querySelector('[data-share-display-intro-checkbox]');
    const intro = preview.querySelector('.social-share-intro');
    if (displayIntroCheckbox && intro) {
        intro.hidden = !displayIntroCheckbox.checked;
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
    if (VARIANTS.some(({ selector }) => event.target.matches(selector))
        || event.target.matches('[data-share-display-intro-checkbox]')
        || event.target.matches('[data-share-networks-sortable] input[type="checkbox"]')) {
        syncPreview();
    }
});

document.addEventListener('share-buttons-networks:reordered', syncPreview);
