# Upgrade

## To 2.10

**`social-publish-enabled` is gone: the publication is on as soon as one network is connected.** A site with a
network connected while the switch read `false` **starts preparing posts the day it upgrades** - the per-network
`*-publish-mode` keys (review by default) still keep every post waiting for a reading, set them before upgrading
if one reads `auto`. The "Publications" and "Créneaux de publication" screens are always listed, saying so while
no network is connected.

**`social-bluesky-app-password` is gone: Bluesky is only connected through OAuth.** A site posting with an app
password alone has to connect again, from the Bluesky tile of the new **"Connexions"** screen (sidebar, "Social"),
**from its production address**: the connection is refused on any host other than `site-url`'s. The client key id
is now its RFC 7638 thumbprint instead of `c975l-social-1`, so Bluesky may hold the former key set in its cache for
up to ten minutes after deploying - a connection or refresh failing once in that window succeeds on the next try.

**"Connexions" replaces the "Connecter Google", "Connecter Meta" and "Connecter Bluesky" links**, one tile per
network, and the OAuth callbacks land on it. The "Boutons de partage" screen is always listed too, saying when the
band is off.

**Constructors:** `MenuProvider` only takes the `ConfigServiceInterface`; `ShareButtonsSettingsCrudController`
takes two more arguments, `ConfigRepository` and `ConfigEditUrlResolver`; `BlueskyPublisher` no longer takes an
`HttpClientInterface`. Nothing to do with autowiring; a class extending them or built by hand follows.

The two stored rows are now undeclared: remove them with `c975l:config:prune` (or from ConfigBundle's prune screen).

**LinkedIn joins the networks**, posting on a member's own profile: run `c975l:config:load-all` for the six
`social-linkedin-*` keys, then follow the README's "LinkedIn profile, step by step". Nothing changes for a site
leaving them empty. `SocialConnectionsController` takes a `LinkedInClient` last.

**New task every 15 minutes, `c975l:social:publish --planned`**, declared by `SocialMaintenanceTaskProvider`: a post
planned for a moment now goes out at that moment, no longer at the first slot after it. Nothing to schedule by hand.
**New configs `social-calendar-hour-start` and `social-calendar-hour-end`** (6 and 23): the hours of the calendar's week.

**New config `social-image-format`** (`framed` by default, the visual unchanged) and **a management stylesheet**,
`public/css/management.min.css`: run `assets:install` so the calendar is styled. `SocialImageExporter` takes a
`ConfigServiceInterface` last.

**New config `social-ai-guidelines`**: run `c975l:config:load-all`, then write the site's tone there for its AI to
write the posts (with the rephrase key set).

**Needs a migration for the new `social_post.planned_at` column** (nullable, indexed): `doctrine:migrations:diff`
then `doctrine:migrations:migrate`. The new `approved` status fits the existing `status` column.

**Constructors:** `SocialPostCrudController` takes an `EntityManagerInterface` last, `SocialPublisher` a `SocialPlanner`
and a `SocialPostWriter` last, `SocialGuidedProjectProvider` an `UrlGeneratorInterface` last. Nothing to do with autowiring.

## To 2.7.2

**Update `c975l/core-bundle` to `^1.23` first, then run `c975l:config:load-all`.** The drawer these settings move
to is named by this bundle, and core-bundle refused an unknown one until 1.23 — moving them under an older core
would make them unsavable from the back office. Until the command runs, they sit in the drawer they had, values
untouched: nothing reads a setting by its group, and no page changes.

## To 2.6

**The `Review` entity moved to UiBundle, which now maps and migrates it.** This bundle owns no table anymore: it brings the platforms the reviews are imported from, Ui holds the reviews themselves, their moderation screen and their display. Four things to go through, in this order — the first one before `composer update`, or the site does not boot.

- **Remove the mapping**, in `config/packages/doctrine.yaml`, before updating: the `c975LSocialBundle` block added in 2.4 points at `vendor/c975l/social-bundle/src/Entity`, a directory this release deletes, and Doctrine throws a `MappingException` at warmup on a `dir` that no longer exists. The `Review` now travels with UiBundle's own entities, already mapped.

```yaml
# config/packages/doctrine.yaml - delete this block
doctrine:
    orm:
        mappings:
            c975LSocialBundle:
                type: attribute
                dir: '%kernel.project_dir%/vendor/c975l/social-bundle/src/Entity'
                prefix: 'c975L\SocialBundle\Entity'
```

- **Migrate, then backfill the new `status` column** [Needs db update]. UiBundle's `Review` carries a status the former entity had no equivalent of, and its `Pending` default is a PHP one, with no SQL default behind it: the generated migration adds a `NOT NULL` column that leaves every already imported review out of `published`, and the pages serving them show none.

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

```sql
UPDATE site_review SET status = 'published' WHERE status = '' OR status IS NULL;
```

- **Turn the reviews back on**, `social-enable-reviews` having become **`ui-enable-reviews`** (bool, `false` by default). A site that had the reviews enabled loses them silently otherwise, the new key starting off:

```bash
php bin/console c975l:config:load-all
```

then tick **Activer les avis** in the site Configuration.

- **Re-pick the source of every Collection block displaying the reviews.** The collection source key changed from `social.collection.reviews` to `ui.collection.reviews`, and a block still holding the old one renders zero review without any error — its displayed label ("Avis clients") did not change. Open each such page, reselect the **Avis clients** source in the Collection block, and save.

## To 2.4

**The bundle now ships an entity, and its first routes.** Customer reviews are stored in a `Review` entity (table `site_review`), and the Google connection needs two controller routes — neither of which an application declared before, this bundle having had no table and no route of its own until now. Two things to add, both of them optional if you never enable the reviews (the entity mapping this release also asked for is gone: the `Review` moved to UiBundle in 2.6, see above).

- **Import the controllers**, in `config/routes.yaml`:

```yaml
c975l_social:
    resource: '@c975LSocialBundle/src/Controller/'
    type: attribute
```

- **Generate and run the migration** [Needs db update]:

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
php bin/console c975l:config:load-all
```

Nothing else changes: the social links and share buttons stay singleton blocks, with no table and no route.

## To 1.4

**The bundle now requires PHP 8.4 and Symfony 8.** It used to declare `"php": ">=8.1"` and `"symfony/*": "*"`, an unbound constraint that let Composer resolve Symfony against whatever PHP the application ran on - so an application on PHP 8.2 silently got Symfony 7 with a bundle only ever tested against Symfony 8. The requirements now say what is actually built and tested: `"php": ">=8.4"` and `"symfony/*": "^8.0"`. If your application is still on Symfony 7, stay on the previous release until you migrate - `composer update` will simply refuse to move rather than break anything.

## To 1.3

### Share buttons: the single `style` setting became `shape` + `fill`

The seven values `style` accepted were fixed pairs of a button *shape* and a button *fill*, so two fills were locked to one arbitrary shape: `outline` was always a circle, `minimal` always a square. Picking a round `minimal` button, or a wide `outline` one, was simply not reachable. The two are now separate settings — five shapes × four fills, so any of the twenty combinations is (`transparent` being a fill this change adds).

| Shape | Box | Corners |
| --- | --- | --- |
| `wide` | 65×50 | square |
| `ellipse` | 65×50 | fully round |
| `square` | 50×50 | square |
| `rounded` | 50×50 | 12px |
| `circle` | 50×50 | fully round |

| Fill | Paints the box with |
| --- | --- |
| `solid` | the network's own brand color |
| `transparent` | one translucent white, for a band painted through `--social-share-background` |
| `outline` | a brand-colored ring that fills in on hover |
| `minimal` | nothing — the icon alone |

### What to do [BC-Break]

**The old `style` values are not read anymore.** Nothing expands them: a band whose shape and fill aren't both saved renders at the defaults, `wide` + `solid`. Two things to go through on a site upgrading the bundle:

- **Re-save the settings** in the dashboard (Share buttons → shape + fill), which writes the new pair and drops the stale `style` key. Until then, the band renders `wide` + `solid` whatever it used to look like.
- **Update every `share_buttons()` call** passing an old style name, using the table below.

| Old `style` | Now |
| --- | --- |
| `distinct` | `'wide', 'solid'` |
| `ellipse` | `'ellipse', 'solid'` |
| `square` | `'square', 'solid'` |
| `rounded` | `'rounded', 'solid'` |
| `circle` | `'circle', 'solid'` |
| `outline` | `'circle', 'outline'` |
| `minimal` | `'square', 'minimal'` |

### What did change

- `ShareButtonsServiceInterface::getStyles()` is gone, replaced by `getShapes()` and `getFills()`. Implement the two if you had your own implementation of that interface.
- `share_buttons()` gained a `fill` parameter **in third position**, right after `shape` — a call passing `alignment` or anything after it positionally (`{{ share_buttons('main', 'distinct', 'center', ...) }}`) must insert the fill: `{{ share_buttons('main', 'wide', 'solid', 'center', ...) }}`.
- The rendered band carries `social-share--shape-{shape}` and `social-share--fill-{fill}` instead of a single `social-share--{style}`. A stylesheet targeting the old class names needs updating.
- `--social-share-btn-width`/`-height`/`-radius` and `--social-share-btn-background`/`-hover` have a per-variant default, one value per shape or fill. Declaring one in `:root` replaces all of them at once and collapses every variant into a single look — the shape and fill picked in the dashboard then change nothing visible. Set them in the app's own `app.css` for a look no combination covers, not in its `theme.css`.
