<?php

namespace viesrood\imgixkit\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use Imgix\UrlBuilder;
use Throwable;
use viesrood\imgixkit\exceptions\ImgixException;
use viesrood\imgixkit\models\ImgixImage;
use viesrood\imgixkit\models\Settings;
use viesrood\imgixkit\Plugin;

final class UrlService extends Component
{
    private const RESERVED_PARAMS = ['s', 'ixlib'];

    /**
     * Injectable so the service can be tested without a running Craft
     * application. In production this stays null and the settings simply come
     * from the plugin.
     */
    public ?Settings $pluginSettings = null;

    /** @var array<string, array> */
    private array $resolvedSources = [];

    /** @var array<string, UrlBuilder> */
    private array $builders = [];

    /** @var array<int, array{int, int}> */
    private array $assetSizes = [];

    /** @var array<int, array{x: float, y: float}> */
    private array $focalPoints = [];

    public function image(Asset|string|null $image, array $params = [], ?string $sourceName = null): ?ImgixImage
    {
        if ($image === null || $image === '') {
            return null;
        }

        try {
            $passthrough = $this->passthrough($image);
            if ($passthrough !== null) {
                return $passthrough;
            }

            $settings = $this->settings();
            [$sourceName, $source] = $this->source($sourceName);
            $path = $this->resolvePath($image, $source);
            $params = $this->normalizeParams(array_merge($settings->defaultParams, $params));
            $params = $this->applyFocalPoint($image, $params);
            $params = $this->preventUpscale($image, $params);
            $params = $this->applyVersion($image, $params);
            [$width, $height] = $this->dimensions($image, $params);
            $url = $this->builder($sourceName, $source)->createURL($path, $params);

            return new ImgixImage(
                $url,
                $width,
                $height,
                $height > 0 ? $width / $height : 0.0,
                $params,
                $path,
            );
        } catch (Throwable $exception) {
            return $this->handleFailure($image, $params, $exception);
        }
    }

    public function originalUrl(Asset|string $image, ?string $sourceName = null): string
    {
        [$sourceName, $source] = $this->source($sourceName);
        return $this->builder($sourceName, $source)->createURL($this->resolvePath($image, $source), []);
    }

    public function srcset(Asset|string|null $image, array $params = [], array $widths = [], ?string $sourceName = null): string
    {
        if ($image === null || $image === '') {
            return '';
        }

        try {
            if (array_intersect(['w', 'h', 'dpr'], array_keys($params)) !== []) {
                throw new ImgixException('A width srcset may not contain w, h or dpr. Use ar for a fixed aspect ratio.');
            }

            // A vector needs no width variants.
            if ($this->passthrough($image) !== null) {
                return '';
            }

            $customWidths = $widths !== [];
            $widths = $customWidths ? $widths : $this->settings()->srcsetWidths;
            $widths = array_values(array_unique(array_map('intval', $widths)));
            sort($widths, SORT_NUMERIC);
            $widths = array_values(array_filter($widths, static fn(int $width): bool => $width > 0 && $width <= 8192));

            if ($image instanceof Asset && $this->settings()->preventUpscale) {
                [$sourceWidth] = $this->assetSize($image);
                if ($sourceWidth > 0) {
                    $widths = array_values(array_filter($widths, static fn(int $width): bool => $width <= $sourceWidth));
                    if ($widths === []) {
                        $widths[] = $sourceWidth;
                    } elseif (!$customWidths && end($widths) < $sourceWidth && count($widths) < 12) {
                        $widths[] = $sourceWidth;
                    }
                }
            }

            $pairs = [];
            foreach ($widths as $width) {
                $variant = $this->image($image, array_merge($params, ['w' => $width]), $sourceName);
                if ($variant === null) {
                    continue;
                }
                // On a fallback every variant points at the same original, so a
                // srcset would be misleading. Let the browser use the src instead.
                if ($variant->isFallback) {
                    return '';
                }
                $pairs[] = $variant->url . ' ' . $variant->width . 'w';
            }
            return implode(', ', array_unique($pairs));
        } catch (Throwable $exception) {
            return $this->degrade($exception, ['method' => 'srcset'], '');
        }
    }

    public function dprSrcset(Asset|string|null $image, array $params, array $dprs = [], ?string $sourceName = null): string
    {
        if ($image === null || $image === '') {
            return '';
        }

        try {
            if (!isset($params['w']) && !isset($params['h'])) {
                throw new ImgixException('A DPR srcset requires w or h.');
            }

            if ($this->passthrough($image) !== null) {
                return '';
            }

            $dprs = $dprs ?: $this->settings()->dprs;
            $quality = [1 => 75, 2 => 50, 3 => 35, 4 => 23, 5 => 20];
            $pairs = [];
            $safeBaseParams = $image instanceof Asset ? $this->preventUpscale($image, $params) : $params;
            foreach (array_unique(array_map('intval', $dprs)) as $dpr) {
                if ($dpr < 1 || $dpr > 5) {
                    throw new ImgixException('DPR values must be between 1 and 5.');
                }
                if ($image instanceof Asset && $this->settings()->preventUpscale) {
                    [$sourceWidth, $sourceHeight] = $this->assetSize($image);
                    [$baseWidth, $baseHeight] = $this->dimensions($image, $safeBaseParams);
                    if (
                        ($baseWidth > 0 && $baseWidth * $dpr > $sourceWidth) ||
                        ($baseHeight > 0 && $baseHeight * $dpr > $sourceHeight)
                    ) {
                        continue;
                    }
                }
                $variantParams = array_merge($params, ['dpr' => $dpr]);
                if (!isset($params['q'])) {
                    $variantParams['q'] = $quality[$dpr];
                }
                $variant = $this->image($image, $variantParams, $sourceName);
                if ($variant === null) {
                    continue;
                }
                if ($variant->isFallback) {
                    return '';
                }
                $pairs[] = $variant->url . ' ' . $dpr . 'x';
            }
            return implode(', ', $pairs);
        } catch (Throwable $exception) {
            return $this->degrade($exception, ['method' => 'dprSrcset'], '');
        }
    }

    /**
     * Typically called on every page render for a preconnect hint, so a
     * configuration mistake here must never take the whole site down.
     */
    public function domain(?string $sourceName = null): string
    {
        try {
            [, $source] = $this->source($sourceName);
            return 'https://' . $source['domain'];
        } catch (Throwable $exception) {
            return $this->degrade($exception, ['method' => 'domain', 'source' => $sourceName], '');
        }
    }

    public function sourceConfig(?string $sourceName = null): array
    {
        return $this->source($sourceName);
    }

    /**
     * Public so diagnostics can validate the configured defaultParams before
     * the first page load.
     */
    public function validateParams(array $params): array
    {
        return $this->normalizeParams($params);
    }

    private function settings(): Settings
    {
        return $this->pluginSettings ??= Plugin::getInstance()->getSettings();
    }

    private function source(?string $sourceName): array
    {
        $settings = $this->settings();
        $sourceName ??= $settings->defaultSource;

        if (isset($this->resolvedSources[$sourceName])) {
            return [$sourceName, $this->resolvedSources[$sourceName]];
        }

        $source = $settings->sources[$sourceName] ?? null;
        if (!is_array($source)) {
            throw new ImgixException("Unknown Imgix source: $sourceName");
        }
        if (!preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/i', (string)($source['domain'] ?? ''))) {
            throw new ImgixException("Invalid domain for Imgix source: $sourceName");
        }
        $source += [
            'sourceType' => 'webFolder',
            'signingKey' => '',
            'apiKey' => '',
            'volumeMap' => [],
        ];
        if ($source['sourceType'] === 'webProxy' && $source['signingKey'] === '') {
            throw new ImgixException("Web Proxy source $sourceName requires a signing key.");
        }

        $this->resolvedSources[$sourceName] = $source;
        return [$sourceName, $source];
    }

    private function builder(string $sourceName, array $source): UrlBuilder
    {
        return $this->builders[$sourceName] ??= new UrlBuilder(
            $source['domain'],
            true,
            (string)$source['signingKey'],
            false,
        );
    }

    /**
     * Craft counts SVG as kind "image", but a vector gains nothing from Imgix:
     * rasterising only makes it heavier and less sharp.
     */
    private function passthrough(Asset|string $image): ?ImgixImage
    {
        if (!$image instanceof Asset || strtolower((string)$image->getExtension()) !== 'svg') {
            return null;
        }
        $url = $image->getUrl();
        if ($url === null || $url === '') {
            return null;
        }

        [$width, $height] = $this->assetSize($image);
        return new ImgixImage(
            $url,
            $width,
            $height,
            $height > 0 ? $width / $height : 0.0,
            [],
            (string)$image->getPath(),
            false,
            true,
        );
    }

    private function resolvePath(Asset|string $image, array $source): string
    {
        if ($image instanceof Asset) {
            if ($image->kind !== 'image') {
                throw new ImgixException("Asset {$image->id} is not an image.");
            }
            $handle = $image->getVolume()->handle;
            if (!array_key_exists($handle, $source['volumeMap'])) {
                throw new ImgixException("Volume $handle is not mapped to this Imgix source.");
            }
            $path = implode('/', array_filter([
                trim((string)$source['volumeMap'][$handle], '/'),
                trim((string)$image->getPath(), '/'),
            ], static fn(string $value): bool => $value !== ''));
        } else {
            $path = trim($image);
            $isUrl = filter_var($path, FILTER_VALIDATE_URL) !== false;
            if ($source['sourceType'] === 'webProxy') {
                if (!$isUrl || !str_starts_with(strtolower($path), 'https://')) {
                    throw new ImgixException('Web Proxy sources require an absolute HTTPS URL.');
                }
                return $path;
            }
            // str_starts_with('//') catches protocol-relative URLs, which
            // FILTER_VALIDATE_URL does not recognise as a URL.
            if ($isUrl || str_contains($path, '://') || str_starts_with($path, '//')) {
                throw new ImgixException('Web Folder sources only accept relative paths.');
            }
            $path = trim($path, '/');
        }

        if ($path === '' || str_contains($path, "\0") || preg_match('~(^|/)\.\.(/|$)~', $path)) {
            throw new ImgixException('Unsafe or empty Imgix source path.');
        }
        return str_replace('\\', '/', $path);
    }

    private function normalizeParams(array $params): array
    {
        $normalized = [];
        foreach ($params as $key => $value) {
            $key = (string)$key;
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $key) || in_array($key, self::RESERVED_PARAMS, true)) {
                throw new ImgixException("Invalid or reserved Imgix parameter: $key");
            }
            if (is_array($value)) {
                $value = implode(',', array_map('strval', $value));
            } elseif (is_bool($value)) {
                $value = $value ? 1 : 0;
            } elseif (!is_scalar($value) && $value !== null) {
                throw new ImgixException("Imgix parameter $key must be scalar.");
            }
            if ($value !== null && $value !== '') {
                $normalized[$key] = $value;
            }
        }
        foreach (['w', 'h'] as $dimension) {
            if (isset($normalized[$dimension])) {
                $normalized[$dimension] = (int)$normalized[$dimension];
                if ($normalized[$dimension] < 1 || $normalized[$dimension] > 8192) {
                    throw new ImgixException("Imgix parameter $dimension must be between 1 and 8192.");
                }
            }
        }
        return $normalized;
    }

    private function applyFocalPoint(Asset|string $image, array $params): array
    {
        if (!$image instanceof Asset || ($params['fit'] ?? null) !== 'crop') {
            return $params;
        }
        if (array_intersect(['crop', 'fp-x', 'fp-y', 'fp-z'], array_keys($params)) !== []) {
            return $params;
        }
        $focalPoint = $this->focalPoint($image);
        $params['crop'] = 'focalpoint';
        $params['fp-x'] = $focalPoint['x'];
        $params['fp-y'] = $focalPoint['y'];
        return $params;
    }

    /**
     * Ties the URL to the file's last modified time. Replace an asset and every
     * URL changes, so Imgix renders the new file and browsers stop using their
     * cached copy. That turns purging into a nice-to-have rather than the only
     * way to get an updated image out.
     */
    private function applyVersion(Asset|string $image, array $params): array
    {
        if (!$image instanceof Asset || !$this->settings()->versionUrls || isset($params['v'])) {
            return $params;
        }
        $timestamp = $image->dateModified?->getTimestamp();
        if ($timestamp !== null) {
            $params['v'] = $timestamp;
        }
        return $params;
    }

    private function preventUpscale(Asset|string $image, array $params): array
    {
        if (!$image instanceof Asset || !$this->settings()->preventUpscale) {
            return $params;
        }
        [$sourceWidth, $sourceHeight] = $this->assetSize($image);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return $params;
        }
        if (isset($params['w'], $params['h'])) {
            $scale = min(1, $sourceWidth / $params['w'], $sourceHeight / $params['h']);
            $params['w'] = max(1, (int)floor($params['w'] * $scale));
            $params['h'] = max(1, (int)floor($params['h'] * $scale));
        } elseif (isset($params['w'])) {
            $maxWidth = $sourceWidth;
            $ratio = $this->parseRatio($params['ar'] ?? null);
            if ($ratio > 0) {
                $maxWidth = min($maxWidth, (int)floor($sourceHeight * $ratio));
            }
            $params['w'] = min($params['w'], $maxWidth);
        } elseif (isset($params['h'])) {
            $maxHeight = $sourceHeight;
            $ratio = $this->parseRatio($params['ar'] ?? null);
            if ($ratio > 0) {
                $maxHeight = min($maxHeight, (int)floor($sourceWidth / $ratio));
            }
            $params['h'] = min($params['h'], $maxHeight);
        }
        return $params;
    }

    private function dimensions(Asset|string $image, array $params): array
    {
        [$sourceWidth, $sourceHeight] = $image instanceof Asset ? $this->assetSize($image) : [0, 0];
        $width = isset($params['w']) ? (int)$params['w'] : 0;
        $height = isset($params['h']) ? (int)$params['h'] : 0;
        $ratio = $this->parseRatio($params['ar'] ?? null);

        if ($ratio > 0) {
            $height = $height ?: ($width > 0 ? (int)round($width / $ratio) : 0);
            $width = $width ?: ($height > 0 ? (int)round($height * $ratio) : 0);
        } elseif ($width > 0 && $height === 0 && $sourceWidth > 0 && $sourceHeight > 0) {
            $height = (int)round($width * $sourceHeight / $sourceWidth);
        } elseif ($height > 0 && $width === 0 && $sourceWidth > 0 && $sourceHeight > 0) {
            $width = (int)round($height * $sourceWidth / $sourceHeight);
        } elseif ($width === 0 && $height === 0) {
            $width = $sourceWidth;
            $height = $sourceHeight;
        } elseif (in_array($params['fit'] ?? '', ['clip', 'max', 'clamp'], true) && $sourceWidth > 0 && $sourceHeight > 0) {
            $scale = min($width / $sourceWidth, $height / $sourceHeight, 1);
            $width = (int)round($sourceWidth * $scale);
            $height = (int)round($sourceHeight * $scale);
        }
        return [$width, $height];
    }

    private function parseRatio(mixed $value): float
    {
        if (is_numeric($value) && (float)$value > 0) {
            return (float)$value;
        }
        if (is_string($value) && preg_match('/^(\d+(?:\.\d+)?):(\d+(?:\.\d+)?)$/', $value, $matches) && (float)$matches[2] > 0) {
            return (float)$matches[1] / (float)$matches[2];
        }
        return 0.0;
    }

    /**
     * @return array{int, int}
     */
    private function assetSize(Asset $image): array
    {
        return $this->assetSizes[spl_object_id($image)] ??= [
            (int)$image->getWidth(),
            (int)$image->getHeight(),
        ];
    }

    /**
     * @return array{x: float, y: float}
     */
    private function focalPoint(Asset $image): array
    {
        return $this->focalPoints[spl_object_id($image)] ??= (static function() use ($image): array {
            $focalPoint = $image->getFocalPoint();
            return [
                'x' => round((float)$focalPoint['x'], 4),
                'y' => round((float)$focalPoint['y'], 4),
            ];
        })();
    }

    private function shouldThrow(): bool
    {
        // Without an application (console bootstrap, tests) behave like devMode:
        // better to fail loudly than to degrade silently where nothing is logged.
        $devMode = Craft::$app?->getConfig()->getGeneral()->devMode ?? true;
        return $devMode || !$this->settings()->fallbackInProduction;
    }

    private function wrap(Throwable $exception): ImgixException
    {
        return $exception instanceof ImgixException
            ? $exception
            : new ImgixException($exception->getMessage(), (int)$exception->getCode(), $exception);
    }

    /**
     * Throws in devMode (or when fallbackInProduction is off) and otherwise
     * degrades to $fallback with a line in the imgixkit log.
     */
    private function degrade(Throwable $exception, array $context, mixed $fallback): mixed
    {
        $wrapped = $this->wrap($exception);
        if ($this->shouldThrow()) {
            throw $wrapped;
        }
        Craft::error($context + ['message' => $wrapped->getMessage()], 'imgixkit');
        return $fallback;
    }

    private function handleFailure(Asset|string $image, array $params, Throwable $exception): ImgixImage
    {
        $wrapped = $this->wrap($exception);
        if ($this->shouldThrow() || !$image instanceof Asset || !$image->getUrl()) {
            throw $wrapped;
        }

        Craft::error([
            'message' => $wrapped->getMessage(),
            'assetId' => $image->id,
            'volume' => $image->getVolume()->handle,
        ], 'imgixkit');
        [$width, $height] = $this->assetSize($image);
        return new ImgixImage(
            $image->getUrl(),
            $width,
            $height,
            $height > 0 ? $width / $height : 0.0,
            $params,
            (string)$image->getPath(),
            true,
        );
    }
}
