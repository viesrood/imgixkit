# Changelog

## 1.2.0 - 2026-07-29

- Asset URLs now carry `v=<last modified timestamp>`, so replacing a file
  changes its URL and both Imgix and the browser pick the new version up on
  their own. Purging becomes a nice-to-have instead of the only way to ship an
  updated image. Turn it off with `versionUrls => false`, or pass your own `v`.

## 1.1.0 - 2026-07-29

- On-demand purging: a **Purge from Imgix** bulk action on the Assets index and
  a `php craft imgixkit/purge --asset-id=` / `--volume=` console command. Both
  ignore `autoPurge` and the de-duplication window, since they were asked for
  explicitly.
- `PurgeService::enqueueAsset()` takes a `$force` flag and returns the number of
  jobs queued; `PurgeService::isConfigured()` reports whether any source can
  purge at all.
- Diagnostics flag an Imgix v1 API key. Those were retired, and every purge made
  with one fails without saying so.
- The console controller is now `ImgixKitController`, so `php craft imgixkit`
  and `php craft imgixkit/purge` both resolve.

## 1.0.0 - 2026-07-29

Initial release.

- Imgix URLs from Craft assets or relative paths, using native Imgix parameter
  names rather than a translation layer.
- Width srcsets and DPR srcsets, with per-DPR quality defaults.
- Craft focal points are translated to `crop=focalpoint` on `fit=crop`.
- Upscale prevention: `w`, `h` and srcset widths are clamped to what the source
  file can actually deliver.
- Explicit volume-to-source mapping; unmapped volumes are refused, as are path
  traversal, null bytes and absolute or protocol-relative URLs on a web folder
  source.
- Optional URL signing and a de-duplicated automatic purge queue on asset
  replace, move and delete.
- SVG assets bypass Imgix and keep their own URL.
- Configurable degradation: throws in devMode, falls back to the Craft URL in
  production with a log entry, so a misconfiguration never takes a site down.
- Control panel utility and console command for diagnostics, which never reveal
  signing tokens or API keys.
- Dutch translation of the control panel strings.
