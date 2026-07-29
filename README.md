# ImgixKit

A focused [Imgix](https://www.imgix.com) integration for Craft CMS 5. ImgixKit
generates Imgix URLs and responsive variants, and nothing else: there are no
local image transforms and no compatibility layer over other transform plugins.
You write native Imgix parameters, so anything Imgix supports works on day one
without waiting for the plugin to add it.

**Features**

- Native Imgix parameter names, not a translated subset
- Width srcsets and DPR srcsets, with sensible per-DPR quality defaults
- Craft focal points are applied automatically on `fit=crop`
- Upscale prevention: never asks Imgix for more pixels than the source has
- Explicit volume-to-source mapping, so an asset can never resolve outside the
  source you configured
- Optional URL signing, and automatic cache purging on replace, move and delete
- SVG assets bypass Imgix and keep their own URL
- Degrades to the original Craft URL instead of taking the page down when the
  configuration is wrong
- Diagnostics in the control panel and on the console

## Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Twig](#twig)
4. [Behaviour worth knowing](#behaviour-worth-knowing)
5. [Automatic purging](#automatic-purging)
6. [Diagnostics](#diagnostics)
7. [Tests](#tests)

## Installation

```bash
composer require viesrood/imgixkit
php craft plugin/install imgixkit
```

Requires Craft CMS 5 and PHP 8.2 or newer.

## Configuration

Configuration lives in `config/imgixkit.php`. Secrets belong in environment
variables:

```dotenv
IMGIX_SIGNING_KEY=
IMGIX_API_KEY=
```

`IMGIX_SIGNING_KEY` is required as soon as Secure URLs is enabled on the Imgix
source. `IMGIX_API_KEY` enables the de-duplicated purge queue.

```php
<?php

use craft\helpers\App;

return [
    'defaultSource' => 'default',
    'sources' => [
        'default' => [
            'domain' => 'example.imgix.net',
            'sourceType' => 'webFolder',
            'signingKey' => App::env('IMGIX_SIGNING_KEY') ?: '',
            'apiKey' => App::env('IMGIX_API_KEY') ?: '',
            // Craft volume handle => path within the Imgix source
            'volumeMap' => [
                'images' => '',
            ],
        ],
    ],
    'defaultParams' => [
        'auto' => 'compress,format',
        'cs' => 'srgb',
    ],
    'srcsetWidths' => [320, 400, 500, 640, 768, 960, 1200, 1440, 1680, 1920, 2240, 2560],
    'dprs' => [1, 2, 3],
    'preventUpscale' => true,
    'autoPurge' => true,
    'fallbackInProduction' => true,
];
```

Every source configures an Imgix domain, a source type (`webFolder` or
`webProxy`) and an explicit mapping from Craft volume handle to the path within
the Imgix source. Volumes that are not mapped are refused rather than guessed.

Adding a second source is an extra key under `sources`; pass its name as the
last argument in Twig (`craft.imgixkit.image(asset, params, 'archive')`).
Without that argument `defaultSource` is used.

## Twig

```twig
{% set transformed = craft.imgixkit.image(asset, {
    w: 800,
    h: 450,
    fit: 'crop',
    q: 80
}) %}

{% set srcset = craft.imgixkit.srcset(asset, {
    ar: '16:9',
    fit: 'crop',
    q: 80
}, [400, 600, 800, 1200]) %}

<img
    src="{{ transformed.url }}"
    {% if srcset %}
        srcset="{{ srcset }}"
        sizes="(min-width: 1280px) 800px, 100vw"
    {% endif %}
    width="{{ transformed.width }}"
    height="{{ transformed.height }}"
    loading="lazy"
    decoding="async"
    alt="{{ asset.alt }}"
>
```

| Method | Returns |
|---|---|
| `craft.imgixkit.image(asset, params, source)` | An image model, or `null` for empty input |
| `craft.imgixkit.url(asset, params, source)` | Just the URL |
| `craft.imgixkit.srcset(asset, params, widths, source)` | A width srcset |
| `craft.imgixkit.dprSrcset(asset, params, dprs, source)` | A DPR srcset |
| `craft.imgixkit.domain(source)` | The HTTPS domain, for a preconnect hint |

The image model exposes `url`, `width`, `height`, `aspectRatio`, `params`,
`sourcePath`, `isFallback` and `isPassthrough`, and casts to its URL as a
string.

The first argument may also be a string path (relative for a web folder source,
an absolute HTTPS URL for a web proxy source) instead of a Craft asset.

`s` and `ixlib` are reserved and will be refused, so a template can never
override the URL signature.

## Behaviour worth knowing

**No upscaling.** With `preventUpscale` (on by default) `w` and `h` are reduced
to what the source file can deliver. With both `w` and `h` they are scaled down
by the same factor so the ratio survives; with an `ar` the crop is taken into
account as well. In a srcset, widths above the source width are dropped and the
source width itself is appended if it was missing.

**SVG bypasses Imgix.** Craft counts SVG as `kind = image`, but rasterising a
vector only makes it heavier and less sharp. For an SVG, `image()` returns the
regular Craft URL with `isPassthrough` set to `true`, and `srcset()` and
`dprSrcset()` return an empty string, since a vector needs no width variants.
Wrap the `srcset` attribute in an `{% if %}` so no empty `srcset=""` ends up in
your HTML. This happens before the volume check: an SVG never reaches Imgix, so
it does not have to live in a mapped volume.

**Falling back instead of crashing.** When something is wrong - unknown source,
invalid domain, unmapped volume - then:

- in devMode, or with `fallbackInProduction` set to `false`, it throws;
- otherwise the error is logged under `imgixkit` and the result degrades:
  `image()` returns the Craft original with `isFallback` set to `true`, while
  `srcset()`, `dprSrcset()` and `domain()` return an empty string.

That last part matters for `domain()`, which is typically called in a base
layout for a preconnect hint and therefore runs on every page. Without this
degradation a missing `config/imgixkit.php` on one environment would turn every
page into a 500 instead of only affecting images.

## Automatic purging

With an `IMGIX_API_KEY` configured, ImgixKit queues a purge job when an asset is
replaced, moved or deleted. This requires a running queue daemon. Purges are
de-duplicated per URL for 60 seconds, a 404 from Imgix counts as "was not
cached", and the job stops after three attempts.

## Diagnostics

```bash
php craft imgixkit
```

The same report is available as the ImgixKit utility in the control panel. It
never shows signing tokens or API keys, only whether they are set. It also
checks that the mapped volume handles actually exist and that `defaultParams`
are valid - both mistakes that would otherwise only surface on the first page
load.

## Tests

```bash
composer install
composer test
```

The unit tests cover path resolution, source validation, parameter
normalisation, upscale prevention and srcset generation. They deliberately do
not boot a Craft application.

## License

MIT. See [LICENSE.md](LICENSE.md).
