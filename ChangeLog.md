# Changelog

## v1.2.1

- Index-page Edit/Delete action buttons now show icon-only with the label as hover title (16/07/2026)

## v1.2

- Added a `share_buttons_display` pickable block kind: drops the site-wide "share_buttons_settings" dashboard singleton into a specific spot in a page's block flow, on top of the layout's own automatic `share_buttons_default()` call - same thin-pointer pattern as `social_links_display` (15/07/2026)
- Gallery showcase's share buttons entry now stands in for `share_buttons_display` (kind set instead of null/reused category), suppressing that kind's own regular preview card the same way the social links showcase already does for `social_links_display` (15/07/2026)
- Simplified `social_links_display`'s block label from "Social links (existing block)" to "Social links" (15/07/2026)

## v1.1.3

- Added test to trigger deprecations (14/07/2026)

## v1.1.2

- Added `GalleryShowcaseProvider`: shows every `social_links` icon style (minimal/colored/outline, sample Facebook/Bluesky/LinkedIn entries) and `share_buttons()` style (sample Facebook/Bluesky/Pinterest entries) in UiBundle's block gallery (13/07/2026)
- Social links showcase now joins `social_links_display`'s own "Navigation" category in the gallery instead of a generic section; share buttons has none to join (13/07/2026)
- Share buttons showcase now also joins the "Navigation" category, reusing `social_links_display`'s category key directly since it has no block kind of its own (13/07/2026)
- Removed `social_links_display`'s now-redundant fixture: the showcase stands in for it and suppresses its own (previously duplicate) preview card (13/07/2026)
- Fixed `_share-buttons.scss` gating all its visual styling (colors/sizes/shapes) behind the 768px mobile-hiding breakpoint, unlike `_social.scss`'s equivalent styling - only the visibility toggle needs that breakpoint now (13/07/2026)

## v1.1.1

- Moved tests to the right place (13/07/2026)

## v1.1

- Added translations for config label (13/07/2026)
- Added tests (13/07/2026)

## v1.0.1

- Added What's new feature (11/07/2026)

## v1.0

- Added share_buttons() Twig function, migrated from the now-abandoned c975L/ShareButtonsBundle (10/07/2026)
- Added a dashboard settings screen to pick the networks/style used site-wide, and a "social-enable-share-buttons" config key to auto-display share buttons on every page (10/07/2026)
- Added a curated set of 30 social/media network icons (flat + official-color square badge, 64x64 SVG) under public/icons/ for the icon picker (10/07/2026)
- Added rendering preview of buttons (links + share) in the admin form (10/07/2026)
- Added outline style for social links (11/07/2026)

## v0.2

- Corrected functionalities (10/07/2026)

## v0.1

- Initial release (10/07/2026)