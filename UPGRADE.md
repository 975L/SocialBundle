# Upgrade

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
