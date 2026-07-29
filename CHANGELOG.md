# Changelog

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
