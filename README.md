# ImgixKit

Serve your Craft CMS 5 images through [Imgix](https://www.imgix.com). Point
ImgixKit at your Imgix source, map your volumes, and every asset turns into a
CDN URL that is transformed on the fly. The srcset, the rendered dimensions and
the focal-point crop are worked out for you.

In Twig you write Imgix's own parameter names, so the full Imgix API is
available from the start.

**Features**

- Imgix URLs from Craft assets and from plain paths, on web folder and web
  proxy sources
- Native Imgix parameters: `w`, `h`, `fit`, `ar`, `q`, `auto`, and everything
  else Imgix offers
- Width srcsets and DPR srcsets, with per-DPR quality defaults
- Rendered `width` and `height` on every result, ready for your `<img>` tag so
  the browser can reserve the space
- Craft focal points become `crop=focalpoint` on `fit=crop`
- Requested sizes are capped to the resolution the source file can deliver
- Explicit volume-to-source mapping, so generated paths stay inside the source
  you configured
- Version-stamped URLs, so replacing a file is picked up without a purge
- Optional URL signing for sources with Secure URLs enabled
- Automatic Imgix cache purging through the queue when an asset is replaced,
  moved or deleted, plus on-demand purging from the control panel or console
- SVG assets keep their own URL, so vectors stay sharp at any size
- Configurable fallback to the Craft URL, with logging, so pages keep rendering
  while you fix a misconfiguration
- Multiple Imgix sources side by side
- Diagnostics in the control panel and on the console
- English and Dutch control panel strings

## Contents

1. [Installation](#installation)
2. [Configuration](#configuration)
3. [Twig](#twig)
4. [Behaviour worth knowing](#behaviour-worth-knowing)
5. [Purging](#purging)
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
    'versionUrls' => true,
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

`s` and `ixlib` are reserved, so the URL signature stays under the plugin's
control.

## Behaviour worth knowing

**Sizes are capped to the source.** With `preventUpscale` (on by default) `w`
and `h` are reduced to what the source file can deliver. With both `w` and `h`
they are scaled down by the same factor so the ratio survives; with an `ar` the
crop is taken into account as well. In a srcset, widths stay at or below the
source width, and the source width itself is appended if it was missing.

**SVG is served straight from Craft.** Craft counts SVG as `kind = image`, and
keeping a vector as a vector keeps it sharp at any size and smaller on the wire.
For an SVG, `image()` returns the regular Craft URL with `isPassthrough` set to
`true`, and `srcset()` and `dprSrcset()` return an empty string, since a vector
needs no width variants. Wrap the `srcset` attribute in an `{% if %}` to keep
your HTML clean. This is decided before the volume check, so an SVG works from
any volume.

**Fallback behaviour.** When the configuration does not resolve - unknown
source, invalid domain, unmapped volume - you choose what happens:

- in devMode, or with `fallbackInProduction` set to `false`, the exception
  surfaces so you see it immediately;
- otherwise the error is logged under `imgixkit` and the result falls back:
  `image()` returns the Craft original with `isFallback` set to `true`, while
  `srcset()`, `dprSrcset()` and `domain()` return an empty string.

This matters most for `domain()`, which is typically called in a base layout for
a preconnect hint and therefore runs on every page. The fallback keeps those
pages rendering on their Craft URLs while you sort the configuration out, for
instance on an environment where `config/imgixkit.php` has not landed yet.

**URLs carry a version stamp.** With `versionUrls` (on by default) every asset
URL gets a `v=<last modified timestamp>`. Replace a file and the URL changes, so
Imgix renders the new file and browsers stop serving their cached copy. This is
what makes purging optional: without it, a replaced image can keep showing the
old version until the Imgix cache expires. Pass your own `v` to override it, or
set `versionUrls` to `false` to leave it off. String paths get no version stamp,
since there is no file to read a timestamp from.

## Purging

With an `IMGIX_API_KEY` configured, ImgixKit queues a purge job when an asset is
replaced, moved or deleted. This requires a running queue daemon. Purges are
de-duplicated per URL for 60 seconds, a 404 from Imgix counts as "was not
cached", and the job stops after three attempts.

You can also purge on demand. Both routes bypass the `autoPurge` setting and the
de-duplication window, because you asked for them explicitly:

- **Control panel**: select assets on the Assets index and choose **Purge from
  Imgix** from the actions menu.
- **Console**:

  ```bash
  php craft imgixkit/purge --asset-id=123
  php craft imgixkit/purge --volume=images
  ```

  One of the two is required, so a stray command cannot purge your whole
  library.

## Diagnostics

```bash
php craft imgixkit
```

The same report is available as the ImgixKit utility in the control panel. It
reports whether signing tokens and API keys are set, keeping the values
themselves out of the output. It also confirms that the mapped volume handles
exist, that `defaultParams` are valid, and that the API key is not one of
Imgix's retired v1 keys - mistakes that would otherwise surface as a blank page
or a purge that silently never happens.

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
