# Changelog

## v1.4.0

Require PHP 8.4 and Symfony 8, and set up the quality toolchain

- `php` is now required in `>=8.4` instead of `>=8.1` (30/07/2026) [BC-Break]
- The `symfony/*` requirements are now constrained to `^8.0` instead of `*` (30/07/2026) [BC-Break]
- The third-party requirements left in `*` are now bounded on their installed version (30/07/2026)
- The `c975l/*` requirements are now bounded on their major (30/07/2026)
- The `c975l/*` requirements are now constrained to `^5` and `^1` instead of `*` (30/07/2026) [BC-Break]
- Updated the requirements in the readme (30/07/2026)
- Added `.codacy.yaml`, `phpcs.xml.dist` and `eslint.config.mjs` (30/07/2026)
- Applied PSR-12 to the codebase (30/07/2026)
- Added `.php-cs-fixer.dist.php`, applying the Symfony coding standards (30/07/2026)
- Added `phpstan.dist.neon`, running the static analysis at level 5 (30/07/2026)
- Added the `CI` GitHub Actions workflow (30/07/2026)
- The local Codacy CLI now runs `eslint@9.39.5` (30/07/2026)

## v1.3.1

Ship the share band as an includable template

- Added `templates/shareButtons/default.html.twig`, the share band a host layout includes (29/07/2026)
- Documented that template as the site-wide auto-display entry point in the readme (29/07/2026)
- Fixed the guided steps highlighting `.action-save`, EasyAdmin naming that button `action-saveAndReturn` (29/07/2026)
- Added a `SocialGuidedProjectProviderTest` case on the save steps' highlight (29/07/2026)

## v1.3.0

Split the share buttons style into shape and fill

- Added `SocialGuidedProjectProvider`, contributing this bundle's guided projects to the dashboard (29/07/2026)
- Added the "Mettre les liens vers vos réseaux" and "Régler les boutons de partage" projects (29/07/2026)
- The share buttons project is only contributed while `social-enable-share-buttons` is on (29/07/2026)
- Added the `label.guided_project_social_*`/`label.guided_step_social_*` translations (29/07/2026)
- Added `SocialGuidedProjectProviderTest` (29/07/2026)
- The share buttons index now shows the translated shape and fill instead of the raw `style` (29/07/2026)
- Split the share buttons `style` setting into `shape` (`wide`, `ellipse`, `square`, `rounded`, `circle`) and `fill` (`solid`, `transparent`, `outline`, `minimal`) (29/07/2026)
- Removed the old `style` values, no longer read anywhere - see UPGRADE.md (29/07/2026) [BC-Break]
- Added a `transparent` fill, one translucent white instead of the brand colors (29/07/2026)
- The band now carries `social-share--shape-{shape}` and `social-share--fill-{fill}` instead of a single `social-share--{style}` (29/07/2026) [BC-Break]
- `ShareButtonsServiceInterface::getStyles()` replaced by `getShapes()` and `getFills()` (29/07/2026) [BC-Break]
- `share_buttons()` gained a `fill` parameter in third position, shifting `alignment` and everything after it (29/07/2026) [BC-Break]
- Added an `UPGRADE.md` (29/07/2026)
- Fixed the dashboard preview stacking the previously selected variant's class under the next one (29/07/2026)
- The block gallery now shows one share buttons card per shape, then one per fill (29/07/2026)
- Added a neutral stand-in band behind the `transparent` fill in the dashboard preview and the block gallery (29/07/2026)
- Added `--social-share-preview-background` and `--social-share-preview-padding` to retune that stand-in band (29/07/2026)
- Documented that the per-variant `--social-share-btn-*` custom properties collapse every variant into one when declared in `:root` (29/07/2026)
- Updated the share buttons admin help procedure (29/07/2026)
- Removed the unused `templates/emails/styles.min.css` (29/07/2026)

## v1.2.11

- Replaced ids by hash in translations (27/07/2026)

## v1.2.10

- Added `--social-share-display`, `--social-share-background`, `--social-share-padding` and `--social-share-gap` custom properties on the share band (27/07/2026)
- Added `--social-share-btn-width`, `--social-share-btn-height`, `--social-share-btn-margin` and `--social-share-btn-radius` custom properties on the share buttons (27/07/2026)
- Added `--social-share-btn-background` and `--social-share-btn-background-hover` custom properties overriding the network brand fill (27/07/2026)
- Documented the share buttons custom properties in the readme (27/07/2026)

## v1.2.9

- Added an optional anchor to the share buttons settings (27/07/2026)
- Added an optional anchor field to the `share_buttons_display` block (27/07/2026)
- Added an optional `id` parameter to `share_buttons()` and `share_buttons_default()` (27/07/2026)
- Added a fallback to the main networks when none is selected in the share buttons settings (27/07/2026)

## v1.2.8

- Replaced the removed `twitter`/`x` networks by `bluesky`/`telegram` in test fixtures (27/07/2026)

## v1.2.7

- Added `SocialLinksExportProvider`/`SocialLinksImportProvider`, plugging the `social_links` singleton Block into ConfigBundle's "Sync" content export/import (24/07/2026)
- Added `ShareButtonsSettingsExportProvider`/`ShareButtonsSettingsImportProvider`, plugging the `share_buttons_settings` singleton Block into ConfigBundle's "Sync" content export/import (24/07/2026)

## v1.2.6

- Added `ImportmapProvider`, declaring `controllers-admin.js`/`controllers.js`'s importmap.php entries for ConfigBundle's `c975l:config:check-importmap` (24/07/2026)

## v1.2.5.1

- Added logo to readme (23/07/2026)

## v1.2.5

- Expanded the explanatory text on the Social links/Share buttons settings edit screens (22/07/2026)
- Removed the detail/view page on Social links and Share buttons settings (22/07/2026)
- Added a Cancel action on both singletons' create/edit screens (22/07/2026)

## v1.2.4

- Added admin help procedures for social links and share buttons setup (21/07/2026)

## v1.2.3

- Shortened translations (20/07/2026)

## v1.2.2

- Tag error (19/07/2026)

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
