/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Keeps the "social_links" preview (see social_links_preview_theme.html.twig) in sync with the "icon style" <select> and "display label" checkbox - both styles share the same icon (CSS alone recolors it, see sass/_social.scss), so this just swaps the list's modifier class and toggles the label, never rebuilds entries. The "links" list itself stays static (see SocialLinksPreviewType for why), its order apart, which follows a drag (see reorderPreview()). Runs once on load too, not just on "change", to match their actual, server-rendered initial state.
const ICON_STYLES = ['minimal', 'colored', 'outline', 'text'];

function syncPreview() {
    const list = document.querySelector('[data-social-links-preview-list]');
    if (!list) return;

    const styleSelect = document.querySelector('[data-social-links-icon-style-select]');
    if (styleSelect) {
        ICON_STYLES.forEach(style => { list.classList.remove(`social-links--${style}`); });
        list.classList.add(`social-links--${styleSelect.value}`);
    }

    // "text" hides the icons through CSS alone (see sass/_social.scss), so the label is all that is left to show: it is forced on whatever the checkbox says, exactly as the real render does (see blocks/SocialLinks.html.twig)
    const textOnly = styleSelect !== null && styleSelect.value === 'text';
    const displayLabelCheckbox = document.querySelector('[data-social-links-display-label-checkbox]');
    if (displayLabelCheckbox) {
        list.querySelectorAll('.social-link-label').forEach(label => {
            label.hidden = !textOnly && !displayLabelCheckbox.checked;
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

// Puts the saved entries in the order their form rows were dragged to, matched by the row index their names carry ("[links][2][position]"). A row added since the last save has no entry to move, and an entry whose row was removed stays, last
function reorderPreview(field) {
    const list = document.querySelector('[data-social-links-preview-list]');
    if (!list) return;

    const entries = [...list.querySelectorAll('[data-social-link-index]')];
    const ordered = [...field.querySelectorAll('[name$="[position]"]')]
        .map(input => input.name.match(/\[links\]\[(\d+)\]\[position\]$/)?.[1])
        .map(index => entries.find(entry => entry.dataset.socialLinkIndex === index))
        .filter(Boolean);

    [...ordered, ...entries.filter(entry => !ordered.includes(entry))].forEach(entry => { list.appendChild(entry); });
}

// Fired by UiBundle's ea-sortable.js once the positions are rewritten, on a drop as on an added row
document.addEventListener('ui.collection.reordered', event => {
    if (event.target.querySelector('[data-social-link-network-select]')) reorderPreview(event.target);
});
