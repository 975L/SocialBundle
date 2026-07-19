/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Keeps the "social_links" preview (see social_links_preview_theme.html.twig) in sync with the "icon style" <select> and "display label" checkbox - both styles share the same icon (CSS alone recolors it, see sass/_social.scss), so this just swaps the list's modifier class and toggles the label, never rebuilds entries. The "links" list itself stays static (see SocialLinksPreviewType for why). Runs once on load too, not just on "change", to match their actual, server-rendered initial state.
const ICON_STYLES = ['minimal', 'colored', 'outline'];

function syncPreview() {
    const list = document.querySelector('[data-social-links-preview-list]');
    if (!list) return;

    const styleSelect = document.querySelector('[data-social-links-icon-style-select]');
    if (styleSelect) {
        ICON_STYLES.forEach(style => list.classList.remove(`social-links--${style}`));
        list.classList.add(`social-links--${styleSelect.value}`);
    }

    const displayLabelCheckbox = document.querySelector('[data-social-links-display-label-checkbox]');
    if (displayLabelCheckbox) {
        list.querySelectorAll('.social-link-label').forEach(label => {
            label.hidden = !displayLabelCheckbox.checked;
        });
    }
}

document.addEventListener('DOMContentLoaded', syncPreview);

document.addEventListener('change', event => {
    if (event.target.matches('[data-social-links-icon-style-select]')
        || event.target.matches('[data-social-links-display-label-checkbox]')) {
        syncPreview();
    }
});
