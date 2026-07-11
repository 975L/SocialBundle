/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

// Shows/hides SocialLinkEntryType's customLabel/customIcon fields depending on whether "network" is
// left empty (the icon-picker widget's hidden input fires "change" on both pick and clear, see
// c975L/UiBundle's assets/js/icon-picker.js) - initial state is server-rendered (see
// social_link_entry_form_theme.html.twig), this only keeps it in sync afterwards, including on
// entries added later by field-collection.js
document.addEventListener('change', event => {
    if (!event.target.matches('[data-social-link-network-select]')) return;

    const wrapper = event.target.closest('.accordion-body')?.querySelector('[data-social-link-network-custom]');
    if (!wrapper) return;

    wrapper.hidden = event.target.value !== '';
});
