---
name: c975l-social
description: "Use this skill when working with social links or share buttons in a Symfony application built on the c975L ecosystem with c975l/social-bundle. Covers the site-wide social links row, the share buttons band and its shapes and fills, the three block kinds, the network icons, the site-wide auto-display and the CSS tokens. Triggers on: social_links, social_links_display, share_buttons_display, share_buttons, share_buttons_default, share_buttons_edit_url, social_link_block, social_link_icon, social-enable-share-buttons, social links, share buttons, network icon, brand color."
---

# c975L SocialBundle

> The social side of a c975L site — one site-wide list of social links, and share buttons for 20 networks, both placed anywhere as blocks. Replaces the former ShareButtonsBundle.

**Package:** `c975l/social-bundle` · **Namespace:** `c975L\SocialBundle\` · **Twig namespace:** `@c975LSocial` · **Translation domain:** `social`

**Key source paths** (relative to the package root):
`src/Service/ShareButtonsService.php`, `src/Twig/`, `src/Form/Block/`, `src/Controller/Management/`, `src/Management/`, `templates/blocks/`, `templates/components/SocialLinks.html.twig`, `templates/shareButtons/`, `config/configs.json`, `config/services.yaml`, `public/icons/`, `sass/_social-brand-colors.scss`, `scaffold/assets/styles/themes/social.css`

**Related documentation:** this package's `README.md` is the exhaustive reference. The block system, the icon service and the media library it builds on live in `c975l/core-bundle`.

## Quick start

```bash
composer require c975l/social-bundle
php bin/console c975l:config:load-all
php bin/console assets:install --symlink
php bin/console c975l:scaffold:install     # copies assets/styles/themes/social.css into the app
```

No route to declare, no entity, no migration: **this bundle creates no table of its own**. Both features
are stored as a singleton UiBundle `Block`, edited from their own dashboard screens.

## The two features, and where their data lives

| Feature | Stored as | Edited in |
| --- | --- | --- |
| Social links | one `social_links` block | **Réseaux sociaux** (`SocialLinksCrudController`) |
| Share buttons settings | one `share_buttons_settings` block | **Boutons de partage** (`ShareButtonsSettingsCrudController`) |

A `Block` can normally only be created by attaching it to a page, so each screen is a small dashboard
CRUD scoped to its own kind. Both sit behind ConfigBundle's `site-role-editor` setting.

## Block kinds

| Kind | Pickable | What it does |
| --- | --- | --- |
| `social_links` | no | the singleton itself: an ordered list of `network` + `url`, an optional rich-text intro, the icon style and the label visibility |
| `social_links_display` | yes | a thin pointer dropping the same site-wide links into any page's block flow |
| `share_buttons_display` | yes | the same, for the dashboard-defined share buttons; its one field is an anchor |

The two `*_display` kinds **store no data of their own** — their form has no display field and their
template renders the singleton. That is the point: one list, edited once, shown wherever it is needed,
never re-entered per page.

## Rendering

```twig
{# the social links row, anywhere in a layout - footer, navbar, a page of your own #}
<twig:c975LSocial:SocialLinks/>

{# share buttons, always-manual entry point, independent of the site-wide setting #}
{{ share_buttons() }}
{{ share_buttons(['facebook', 'linkedin', 'email'], 'ellipse') }}
{{ share_buttons('main', 'circle', 'outline') }}
```

`share_buttons(networks, shape, fill, alignment, displayIcon, displayText, url, id, displayIntro)` —
`networks` defaults to `'main'` (facebook, bluesky, linkedin, pinterest, email), `shape` to `'wide'`,
`fill` to `'solid'`, `url` to the current page.

**Shape and fill are independent.** Shape is the box (`wide`, `ellipse`, `square`, `rounded`,
`circle`), fill is what paints it (`solid` brand color, `outline` ring, `minimal` icon alone,
`transparent` veil) — the 20 combinations are all reachable. They replaced a single `style` parameter
whose values are **gone, not mapped**: a call still passing one renders at the defaults.

Other Twig functions: `social_link_block()` (the singleton block), `social_link_icon()`,
`share_buttons_default(id)` (the dashboard-defined band), `share_buttons_edit_url()`.

## Site-wide auto-display

Two pieces: the **Boutons de partage** screen picks the networks, their order, the shape, the fill, the
invitation line and an optional anchor; the `social-enable-share-buttons` config key (bool, `false` by
default) turns the band on for every page.

The band itself is this bundle's `templates/shareButtons/default.html.twig`, and a layout includes it:

```twig
{{ include('@c975LSocial/shareButtons/default.html.twig', ignore_missing: true) }}
```

**That path is a public contract** — renaming it is a BC break. The `include` is what keeps this bundle
optional: it resolves at runtime, where a direct `share_buttons_default()` call would fail at compile
time on a site not installing it. SiteBundle's layout already carries this line, outside `<main>`.

Hovering the band as an editor raises UiBundle's floating **Editer** button, pointing at the settings
screen.

## Icons

Networks are resolved by key through UiBundle's `IconServiceInterface`, which merges every bundle's
`icons/` directory by filename. This bundle ships 37 flat single-color SVG glyphs under `public/icons/`
(Font Awesome Free 6.5.1, CC BY 4.0 — keep the attribution if you redistribute them).

- **To override one**, drop your own `public/icons/{network}.svg` in the app: the app's directory is
  read last and wins over every bundle's.
- Between bundles, the last `public/bundles/*/icons/` in alphabetical order wins, so a same-named file
  in a package sorting after `c975lsocial` silently shadows the one shipped here.
- There is no pre-colored "official logo" asset: the colored badge is CSS, inverting the black glyph to
  white over a brand-colored background. Every icon exists once.

## Styling

The links row carries `.social-links--minimal` / `--colored` / `--outline` / `--text` and each entry a
`.social-link--{network}` hook. Brand colors for both features come from
`sass/_social-brand-colors.scss`.

The share band is retuned through custom properties, each read with its shipped default as its own
fallback: `--page-share-margin-top`, `--social-share-display`, `--social-share-background`,
`--social-share-padding`, `--social-share-gap`, `--social-share-btn-width` / `-height` / `-radius` /
`-margin`, `--social-share-btn-background` / `-hover`, `--social-share-icon-filter`.

The four box tokens have a **per-variant default**, one value per shape or fill: declaring one in
`:root` collapses every variant into a single look and the dashboard's shape and fill then change
nothing visible. `scaffold/assets/styles/themes/social.css` is the catalogue of what is meant to be set
site-wide, every line shipped commented out at its default.

## What the bundle already contributes

Nothing below is declared in the app: `MenuProvider` (the two dashboard entries), `ProcedureProvider`
(the admin help procedures), `SocialGuidedProjectProvider` (the guided walk-through of each screen,
offered only to who can open it), `WhatsNewProvider`, `ImportmapProvider`, `Service\ScriptProvider`,
`Service\StylesheetProvider`, `Service\BlockFixtureProvider`, and an export/import provider per
singleton (`SocialLinksExportProvider`, `ShareButtonsSettingsExportProvider` and their import twins).

## Do not

- **Do not create an entity or a table for social links or share settings.** Both are singleton blocks;
  adding a table duplicates data the dashboard already owns.
- **Do not call `share_buttons_default()` from a layout.** Use the documented `include` with
  `ignore_missing`, or the site breaks for anyone not installing this bundle.
- **Do not rename `templates/shareButtons/default.html.twig`** — a layout elsewhere includes it by path.
- **Do not pass the old `style` argument** to `share_buttons()`. Pass `shape` and `fill`.
- **Do not re-ship a `{network}.svg` another bundle already carries** — one of the two is shadowed,
  silently, on nothing but the directory order.
- **Do not set `--network-color` in `:root`.** It is declared per network; one value paints every
  button alike.
- **Do not give the `*_display` blocks fields of their own.** They are pointers on purpose; storing a
  copy is what makes a page's links drift from the site's.
- **Do not add a page layout to this bundle.** A satellite never ships one.
