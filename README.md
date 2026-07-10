# SocialBundle

Symfony bundle managing social features for the c975L ecosystem — starting with a user-defined social links block, with post retrieval, scheduled posting, and more planned.

[![GitHub](https://img.shields.io/github/license/975L/SocialBundle)](https://github.com/975L/SocialBundle/blob/master/LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/c975l/social-bundle)](https://packagist.org/packages/c975l/social-bundle)
[![PHP Version](https://img.shields.io/packagist/php-v/c975l/social-bundle)](https://packagist.org/packages/c975l/social-bundle)

---

## Features

- **Social links block**: a `ui.block` kind (`social_links`) storing a user-defined, ordered list of links (label, url, icon) — no hardcoded network list, no dedicated entity/table
- **Admin CRUD** for the social links block via EasyAdmin, outside of any page's block collection
- **Rendering component** to display the block wherever it lives, page-attached or not
- **Icon picker** reusing [c975L/UiBundle](https://github.com/975L/UiBundle)'s searchable `IconPickerType`
- **Stylesheet auto-registration** via UiBundle's `BundleStylesheetProviderInterface` — no manual `<link>` needed
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

No routes to enable and no configuration keys to load: this bundle only contributes an EasyAdmin dashboard entry (auto-registered, see [Admin management](#admin-management)) and a Twig component — nothing front-end-routed of its own yet.

---

## Usage

### Social links block

Registers a `social_links` `ui.block` kind (see [c975L/UiBundle](https://github.com/975L/UiBundle)'s Block system) with a dedicated form (`c975L\SocialBundle\Form\Block\SocialLinksType`) and template (`templates/blocks/SocialLinks.html.twig`). Each link is a `label`, a `url`, and an optional `icon` picked via UiBundle's `IconPickerType` — the same searchable picker used for block buttons, listing every SVG found under `public/icons/` and `public/bundles/*/icons/`. Drop the brand SVGs you need there (e.g. from the [Simple Icons](https://simpleicons.org/) set) and they become selectable immediately, no code change required.

Like any other block kind, `social_links` is selectable from a page's own block picker, so it can be dropped inline in page content too.

### Admin management

Because a `Block` can normally only be created by attaching it to a Page (there's no page-independent block library in UiBundle), `SocialLinksCrudController` gives it its own small dashboard entry, scoped to `kind = social_links` — so it can be created/edited without needing a host page. The menu entry ("Réseaux sociaux") is registered automatically through `MenuProvider`, under the "Management" section. Access is controlled by the `site-role-needed` key in ConfigBundle.

### Rendering the block

```twig
<twig:c975LSocial:SocialLinks/>
```

Under the hood, this looks up the first `social_links` block via `BlockRepository::findOneByKind()` (also exposed as the `social_link_block()` Twig function) and reuses UiBundle's `render_block()`. Renders nothing if no `social_links` block exists yet. Drop it in your footer, navbar, or anywhere else in your layout — it's not tied to any specific location.

### Styling

Ships `.social-links` / `.social-link` styles (flex list of icon links) plus a `footer .social-links` variant for a centered, wrapped layout when used in a page footer. Loaded automatically via the `ui.stylesheet` tag — override the classes in your own SCSS if you need a different look.

---

If this project **helps you save development time**, consider sponsoring via the **Sponsor** button at the top of the GitHub page. Thank you!
