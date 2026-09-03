---
name: c975l-social
description: "Use this skill when working with social links, share buttons or customer reviews in a Symfony application built on the c975L ecosystem with c975l/social-bundle. Covers the site-wide social links row, the share buttons band and its shapes and fills, the three block kinds, the network icons, the site-wide auto-display, the CSS tokens, and the Google Business Profile review import with its pluggable sources. Triggers on: social_links, social_links_display, share_buttons_display, share_buttons, share_buttons_default, share_buttons_edit_url, social_link_block, social_link_icon, social-enable-share-buttons, ui-enable-reviews, ReviewsSourceInterface, ReviewsReplySourceInterface, ReviewSynchronizer, ReviewReplyPublisher, c975l:social:reviews:sync, social-google-oauth-client-id, block-thumbs, BundleStylesheetManagementProviderInterface, ui.management_stylesheet, SocialBlockCacheTagProvider, BlockCacheTagProviderInterface, social links, share buttons, network icon, brand color, customer reviews, Google reviews, Google Business Profile."
---

# c975L SocialBundle

> The social side of a c975L site — one site-wide list of social links, and share buttons for 20 networks, both placed anywhere as blocks. Replaces the former ShareButtonsBundle.

**Package:** `c975l/social-bundle` · **Namespace:** `c975L\SocialBundle\` · **Twig namespace:** `@c975LSocial` · **Translation domain:** `social`

**Key source paths** (relative to the package root):
`src/Service/ShareButtonsService.php`, `src/Twig/`, `src/Form/Block/`, `src/Controller/`, `src/Controller/Management/`, `src/Management/`, `src/Contract/`, `src/Command/ReviewsSyncCommand.php`, `templates/blocks/`, `templates/components/SocialLinks.html.twig`, `templates/shareButtons/`, `config/configs.json`, `config/services.yaml`, `public/icons/`, `sass/_social-brand-colors.scss`, `sass/block-thumbs.scss`, `scaffold/assets/styles/themes/social.css`

**Related documentation:** this package's `README.md` is the exhaustive reference. The block system, the icon service and the media library it builds on live in `c975l/core-bundle`.

## Quick start

```bash
composer require c975l/social-bundle
php bin/console c975l:config:load-all
php bin/console assets:install --symlink
php bin/console c975l:scaffold:install     # copies assets/styles/themes/social.css into the app
```

Social links and share buttons need **no route, no entity and no migration**: both are stored as a
singleton UiBundle `Block`, edited from their own dashboard screens.

Customer reviews do need all three — see [Customer reviews](#customer-reviews) for the routes to import,
the Doctrine mapping to declare and the migration to generate.

## The two singleton features, and where their data lives

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
default) turns the band on for every page. The reviews have the same kind of switch, `ui-enable-reviews`
(see [Customer reviews](#customer-reviews)).

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

`sass/block-thumbs.scss` is a sheet apart, and no part of the site's look: it draws the two pickable
kinds at thumbnail size for the back-office's visual block picker, through EasyAdmin's own `--bs-*`
variables, so a site has nothing to retune there. `StylesheetProvider` serves it through
`getManagementStylesheets()` (`ui.management_stylesheet`), the management screens only — a site
wanting the same silhouettes on a public showcase page contributes the file from its own provider.

## Customer reviews

**The reviews themselves live in UiBundle** (`c975L\UiBundle\Entity\Review`, table `site_review`),
alongside what visitors write on the site: the two are the same thing seen from two sides, and only
`source` tells them apart. Moderation, display and the `ui-enable-reviews` switch are all UiBundle's —
see the `c975l-blocks` skill. This bundle owns **the feed alone**: connecting a platform, pulling its
reviews in, and pushing an answer back out.

| Piece | Class |
| --- | --- |
| Source contract | `Contract\ReviewsSourceInterface` (`getName()`, `isConfigured()`, `fetch()`) |
| Reply capability | `Contract\ReviewsReplySourceInterface` (adds `reply()`) |
| Normalized payload | `Model\ReviewData` |
| Google source | `Service\GoogleBusinessProfileSource` |
| OAuth | `Service\GoogleOAuthClient`, `Controller\GoogleOAuthController` |
| Import | `Service\ReviewSynchronizer`, `Command\ReviewsSyncCommand` |
| Reply out | `Service\ReviewReplyPublisher` |

Sources are auto-tagged by interface (`social.reviews_source`, see `c975LSocialBundle::build()`), so a
new platform is a class implementing `ReviewsSourceInterface` and nothing else.

A run upserts on `(source, external_id)`, marks every imported row `published` — the platform moderated
it already — and removes the reviews its source no longer returns (`ReviewRepository::findMissing()`),
so a review deleted on the platform goes here too. Unless the run brought nothing back at all: an empty
answer is what a revoked token or an exhausted quota looks like, and wiping the wall on one is worse
than showing it stale.

`ReviewReplyPublisher` implements **UiBundle's** `ReviewReplyPublisherInterface`, which is how the
moderation screen reaches it without this bundle owning the screen. Its `supports()` answers false for
a review written on the site and for a source no longer connected, which is what hides the reply field.

The `ui-enable-reviews` key still gates this bundle's own halves of the feature: the "Connecter Google"
link and the two Google guided projects — connecting the listing being the agency's own job
(`ROLE_SUPER_ADMIN`, the OAuth keys being `restricted` configs, and `getGuidedProjects()` also
checking `site-role-admin` and `site-role-editor`, the three screens that parcours walks demanding
one each), reading and displaying the reviews the site's editor. The sync command is deliberately left running while it is off, so a reactivation
shows the reviews imported meanwhile.

**Setup in the consuming app**, on top of the usual `c975l:config:load-all`:

```yaml
# config/routes.yaml — this bundle's only routes
c975l_social:
    resource: '@c975LSocialBundle/src/Controller/'
    type: attribute
```

This bundle maps no entity — the `Review` table travels with UiBundle — and the sync needs no cron
entry of its own: `Scheduler\SocialMaintenanceTaskProvider` declares it nightly, so it is in the
scaffolded `MaintenanceSchedule` as soon as the bundle is installed. The five config slugs are
`social-google-oauth-client-id`, `social-google-oauth-client-secret`,
`social-google-oauth-refresh-token`, `social-google-business-account-id` and
`social-google-business-location-id` — only the first two are typed, `/social/google/connect` writing
the rest.

Google's reviews endpoints need the Cloud project to be **allowlisted** (7-10 business days) and the
OAuth app **published in production**, or its refresh tokens expire every seven days.

The connection is started from **"Connecter Google"**, a `getLinks()` entry tiered `advanced` — so it
sits in the sidebar's collapsed "Avancé" submenu, not next to the CRUD screens.

`Service\SameAsProvider` implements UiBundle's `SameAsProviderInterface`, so a `contact_details` block
publishes the listing (`social-google-listing-url`, a `maps?cid=…` address) and the social links in its
`sameAs` — the property stating the site and those profiles are one business, where the block's own
`mapUrl` publishes `hasMap` and only says a map exists. Nothing is retyped into the contact form.

## What the bundle already contributes

Nothing below is declared in the app: `MenuProvider` (the dashboard entries, each declaring
`site-role-editor` as the bar its own screen states, plus the "Connecter Google" link in the "Avancé"
tier), `ProcedureProvider`
(the admin help procedures), `SocialGuidedProjectProvider` (the guided walk-through of each screen,
offered only to who can open it), `WhatsNewProvider`, `ImportmapProvider`, `Service\ScriptProvider`,
`Service\StylesheetProvider` (the public sheet and the back-office silhouettes both),
`Service\BlockFixtureProvider`, `Service\SocialDemoFixtureProvider` (the two Google reviews a demo
site shows, UiBundle's entity carrying this bundle's rows), `Service\SocialBlockCacheTagProvider` (the
`singleton_block_social_links` tag the `social_links_display` entry carries on top of its own), an
export/import provider per
singleton (`SocialLinksExportProvider`, `ShareButtonsSettingsExportProvider` and their import twins),
`Management\GoogleReviewsHealthCheckProvider` (one row on the health check page, saying whether the
Google connection still answers - the import being the one thing here that stops silently) and
`Scheduler\SocialMaintenanceTaskProvider` (the nightly review sync).

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
- **Do not map a review entity of your own here.** The rows belong to UiBundle, which holds what
  visitors write on the site in the same table; a second one would split the wall in two.
- **Do not edit, delete or hide an imported review anywhere.** A review is its author's statement:
  rewriting it falsifies it, and dropping the ones that displease is what art. L111-7-2 of the French
  consumer code forbids — while the review stays published on the platform anyway. Only the public
  reply is writable, and it goes to the platform before it is stored.
- **Do not call a review platform from a page render.** Quotas are counted per call and the site must
  keep serving its reviews while the platform is down — `c975l:social:reviews:sync` runs on cron.
- **Do not add an `extra` section to the status report.** This bundle holds two back-office
  singletons and no queue of its own, so it has no figure a maintainer would act on that morning.
- **Do not raise a back-office alert on the Google connection.** `GoogleReviewsHealthCheckProvider`
  already states both failing cases, and an alert cannot call Google from a page render.
- **Do not register a `FormThemeProviderInterface` or a `BlockEditUrlProviderInterface` here.** The two
  CRUD form themes are local, passed through `Crud::addFormTheme()`; and the share band is no `Block`
  on a page, its edit url already served by `share_buttons_edit_url()`. Nothing is offered to the
  sitemap or the linkable routes either: both routes are editor-only redirections.
- **Do not make `share_buttons_display` cacheable.** Its render carries the current page's url, so the
  first page's share links would be served on every other page holding that block.
  `social_links_display` is the opposite case and is cached, the singleton's own tag added on top of
  its own - see `Service\SocialBlockCacheTagProvider`.
