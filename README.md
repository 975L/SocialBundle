# SocialBundle

Symfony bundle managing social features for the c975L ecosystem — starting with a user-defined social links block, with post retrieval, scheduled posting, and more planned.

[![GitHub](https://img.shields.io/github/license/975L/SocialBundle)](https://github.com/975L/SocialBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/social-bundle)](https://packagist.org/packages/c975l/social-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/social-bundle)](https://packagist.org/packages/c975l/social-bundle)

---

## Features

- **Social links block**: a `ui.block` kind (`social_links`) storing an ordered list of links (network + url), plus a site-wide icon style (flat/monochrome or colored badge) and label visibility - no dedicated entity/table
- **Admin CRUD** for the social links block via EasyAdmin, outside of any page's block collection
- **Rendering component** to display the block wherever it lives, page-attached or not
- **Pickable pointer block** (`social_links_display`) to drop the same site-wide links into any page's block flow, with no data re-entry
- **Share buttons**: a `share_buttons()` Twig function to let visitors share the current (or a given) page on 15 social networks, with several display styles
- **Share buttons dashboard settings**: pick which networks and which style are used site-wide, and an `enable-share-buttons` config key to auto-display them on every page with no template change
- **Icon picker** reusing [c975L/UiBundle](https://github.com/975L/UiBundle)'s searchable `IconPickerType`
- **Stylesheet auto-registration** via UiBundle's `BundleStylesheetProviderInterface` — no manual `<link>` needed
- **Script auto-registration** via UiBundle's `BundleScriptProviderInterface` — no manual `<script>` needed
- **Admin menu entry** registered automatically via `MenuProviderInterface`

---

## Requirements

- PHP >= 8.1
- [c975L/ConfigBundle](https://github.com/975L/ConfigBundle)
- [c975L/UiBundle](https://github.com/975L/UiBundle)
- EasyAdmin

---

## Installation

### Download

```bash
composer require c975l/social-bundle
```

### Install assets

```bash
php bin/console assets:install --symlink
```

This exposes the bundle's compiled stylesheet at `public/bundles/c975lsocial/css/styles.min.css`.

No routes to enable: this bundle only contributes EasyAdmin dashboard entries (auto-registered, see [Admin management](#admin-management)), a Twig component and Twig functions — nothing front-end-routed of its own. Its single configuration key (`social-enable-share-buttons`, see [Site-wide auto-display](#site-wide-auto-display)) is auto-loaded like any other c975L bundle's, via `php bin/console c975l:config:load-all`.

Share buttons' popup behavior needs its Stimulus controller loaded: as long as your layout renders `{{ importmap(['app']|merge(bundle_scripts())) }}` (see [c975L/UiBundle](https://github.com/975L/UiBundle)'s `bundle_scripts()`), it gets auto-registered — no `assets/bootstrap.js` edit needed.

Symfony's AssetMapper still requires the entrypoint to be declared in your app's `importmap.php` though, since `bundle_scripts()` only feeds names to the `importmap()` Twig function, it doesn't create importmap entries itself:

**Add one entry to `importmap.php`** (one-time, at installation):

```php
'@c975l/social-bundle/controllers.js' => [
  'path' => './vendor/c975l/social-bundle/assets/controllers.js',
  'entrypoint' => true,
],
```

---

## Usage

### Social links block

Registers a `social_links` `ui.block` kind (see [c975L/UiBundle](https://github.com/975L/UiBundle)'s Block system) with a dedicated form (`c975L\SocialBundle\Form\Block\SocialLinksType`) and template (`templates/blocks/SocialLinks.html.twig`). Each link is a `network` (picked from every icon found under `public/icons/` and `public/bundles/*/icons/`) and a `url`; label and icon are derived from the network at render time, not stored. Pick **"Autre"** to fall back to a free-text label and UiBundle's `IconPickerType` for a network with no icon of its own.

Two settings apply to the whole block:

- **Icon style** (`iconStyle`) - `minimal` (the flat, monochrome glyph, inheriting the surrounding text color), `colored` ("Version colorée": the same glyph turned white on a solid, brand-colored pill background) or `outline` (a lighter brand-colored ring on a transparent background, filling in on hover). All CSS only (see [Styling](#styling) below), no separate icon asset - same glyph in every case.
- **Display label** (`displayLabel`) - whether the network name is shown as text next to the icon (still used as `aria-label` regardless).

Unlike most block kinds, `social_links` is tagged `pickable: false` and therefore absent from a page's own block picker: it's a singleton, meant to be edited once and rendered wherever needed (see [Rendering the block](#rendering-the-block)) rather than re-created with duplicate data on every page that wants it.

To insert those same links at a specific spot in a page's block flow (not just the fixed `<twig:c975LSocial:SocialLinks/>` component placement), pick the **`social_links_display`** kind from the page's block picker instead. It's a thin pointer: its own form has no fields and its template just renders `<twig:c975LSocial:SocialLinks/>` internally, so it always reflects the current site-wide links, edited only from [Admin management](#admin-management) — no separate data, no duplication, no extra table.

#### Icons

Ships `public/icons/` with flat, single-color 64×64 SVG glyphs (Font Awesome Free 6.5.1 brand icons, default black fill, no explicit `fill` set) for 37 social/media networks (Instagram, X, YouTube, TikTok, Discord, Threads, Mastodon, GitHub, Twitch, Spotify, SoundCloud, Flickr, Medium, WeChat, Line, Behance, Dribbble, VK, Xing, Messenger, Snapchat, Telegram, Vimeo, plus the ones already covered by UiBundle — see below). Only Font Awesome glyphs are kept here on purpose - no separate, pre-colored "official logo" badge asset: the `colored` icon style above is achieved entirely in CSS (inverting the glyph to white over a solid brand-colored background, see [Styling](#styling)), so every icon only needs to exist once.

`facebook`, `linkedin`, `pinterest`, `whatsapp`, `reddit`, `skype` and `tumblr` deliberately have no `{network}.svg` here: c975L/UiBundle already ships one (used by `share_buttons()` below), and `IconServiceInterface::getIcons()` merges every bundle's `icons/` by filename — a same-named file here would just be silently shadowed by UiBundle's, since `c975lui` sorts after `c975lsocial`.

Icon glyphs are derived from [Font Awesome Free](https://fontawesome.com/) (CC BY 4.0) — keep attribution if you redistribute this bundle's icons on their own.

### Admin management

Because a `Block` can normally only be created by attaching it to a Page (there's no page-independent block library in UiBundle), `SocialLinksCrudController` gives it its own small dashboard entry, scoped to `kind = social_links` — so it can be created/edited without needing a host page. The menu entry ("Réseaux sociaux") is registered automatically through `MenuProvider`, under the "Management" section. Access is controlled by the `site-role-admin` key in ConfigBundle.

The edit form shows a preview of the rendered links below the list. The links themselves are static (reflects the last saved state, not unsaved edits to the list above), but "icon style" and "display label" update it live (see `assets/js/social-links-preview.js`) as you change them.

### Rendering the block

```twig
<twig:c975LSocial:SocialLinks/>
```

Under the hood, this looks up the first `social_links` block via `BlockRepository::findOneByKind()` (also exposed as the `social_link_block()` Twig function) and reuses UiBundle's `render_block()`. Renders nothing if no `social_links` block exists yet. Drop it in your footer, navbar, or anywhere else in your layout — it's not tied to any specific location.

### Styling

Ships `.social-links` / `.social-link` styles (flex list of icon links) plus a `footer .social-links` variant for a centered, wrapped layout when used in a page footer. Loaded automatically via the `ui.stylesheet` tag — override the classes in your own SCSS if you need a different look.

The list also carries a `.social-links--minimal` / `.social-links--colored` / `.social-links--outline` modifier class (from the block's icon style setting, see [Social links block](#social-links-block)) and each `<li>` a `.social-link--{network}` one — hooks to target from your own SCSS rather than opinions this bundle imposes, except for two, both driven by `sass/_social-brand-colors.scss` (shared with `share_buttons()`'s own per-network colors below): under `.social-links--colored`, each `.social-link--{network}` gets a solid, brand-colored badge - background + white icon (same $white-icon-filter trick as `share_buttons()`) + black-or-white text, whichever reads on that background; under `.social-links--outline`, a brand-colored ring on a transparent background instead, filling in (and turning the icon white) on hover. "Autre" entries keep the default, unstyled look in both cases (no brand color to badge them with). Kept deliberately smaller (32px) and visually distinct from `share_buttons()`'s own badges (50-65px, see below) so the two icon rows don't compete for attention on the same page.

### Share buttons

Migrated from the now-abandoned [c975L/ShareButtonsBundle](https://github.com/975L/ShareButtonsBundle). Renders one link per network, each pointing directly at that network's share URL (built server-side from the shared page's URL) — no internal redirect route involved.

```twig
{# Full signature #}
{{ share_buttons(networks, style, alignment, displayIcon, displayText, url) }}

{# Display the main networks with default style #}
{{ share_buttons() }}

{# Custom selection, ellipse style, centered, icon only #}
{{ share_buttons(['facebook', 'linkedin', 'email'], 'ellipse') }}

{# Override the shared URL (defaults to the current page) #}
{{ share_buttons('main', 'distinct', 'center', true, false, 'https://example.com/my-page') }}
```

| Parameter | Type | Default | Description |
| --- | --- | --- | --- |
| `networks` | `string[]\|'main'` | `'main'` | Network keys, or `'main'` for the default set (`facebook`, `bluesky`, `linkedin`, `pinterest`, `email`) |
| `style` | `string` | `'distinct'` | `distinct`, `ellipse`, `circle`, `square`, `rounded`, `outline`, or `minimal` |
| `alignment` | `string` | `'center'` | `left`, `center`, or `right` |
| `displayIcon` | `bool` | `true` | Show the network icon |
| `displayText` | `bool` | `false` | Show the network name |
| `url` | `string\|null` | `null` | URL to share, defaults to the current page |

`distinct` and `ellipse` render wide (65×50) buttons, one filled with the network's brand color; the others render square (50×50) ones - `circle`/`rounded`/`square` filled, `outline` a brand-colored ring that fills on hover, and `minimal` icon-only with no background.

All networks are supported: `facebook`, `bluesky`, `linkedin`, `pinterest`, `email`, `blogger`, `buffer`, `delicious`, `evernote`, `line`, `reddit`, `skype`, `stumbleupon`, `telegram`, `threads`, `tumblr`, `vk`, `whatsapp`, `wordpress`, `xing`. Icons are resolved by network key through UiBundle's `IconServiceInterface` — the same brand SVGs used by the [icon picker](#social-links-block) (`public/icons/facebook.svg` and so on), so dropping your own `public/icons/{network}.svg` in the consuming app overrides a bundle-provided one.

Hidden below 768px (mobile/tablet browsers have their own native share sheet), and clicking a button opens the target in a small centered popup instead of navigating away, via a Stimulus controller (see [Install assets](#install-assets)).

### Site-wide auto-display

To show share buttons on every page without touching a single template, two pieces work together:

- **"Boutons de partage"** in the management menu (`ShareButtonsSettingsCrudController`) — a small dashboard singleton (same `Block`-reuse technique as the [social links block](#social-links-block), no dedicated entity/table) letting you pick which networks and which [style](#share-buttons) are used site-wide. Networks are a drag-sortable checkbox list (see `assets/js/share-buttons-networks-sort.js`) - their order controls the order buttons render in. A live preview (see `assets/js/share-buttons-preview.js`) updates as you check/uncheck/reorder networks or change the style.
- **`social-enable-share-buttons`** — a boolean [c975L/ConfigBundle](https://github.com/975L/ConfigBundle) config key (`false` by default), auto-loaded from this bundle's `config/configs.json`.

[c975L/SiteBundle](https://github.com/975L/SiteBundle)'s base layout calls the `share_buttons_default()` Twig function — which reads those dashboard settings, falling back to `share_buttons()`'s own defaults (`'main'` networks, `'distinct'` style) as long as nothing's been saved yet — gated behind that config key:

```twig
{% if config('social-enable-share-buttons') %}
    {{ share_buttons_default() }}
{% endif %}
```

Flip `social-enable-share-buttons` to `true` in the dashboard and every page gets the buttons; leave it `false` (the default) and nothing changes. Calling `share_buttons()` directly, anywhere else in your own templates, is unaffected by any of this — it's a separate, always-manual entry point.

---

If this project **helps you save development time**, consider sponsoring via the **Sponsor** button at the top of the GitHub page. Thank you!
