<?php

namespace viesrood\imgixkit\services;

use Craft;
use craft\base\Component;
use craft\elements\Asset;
use viesrood\imgixkit\exceptions\ImgixException;
use viesrood\imgixkit\jobs\PurgeJob;
use viesrood\imgixkit\Plugin;

final class PurgeService extends Component
{
    private const ENDPOINT = 'https://api.imgix.com/api/v1/purge';

    public function enqueueAsset(Asset $asset): void
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->autoPurge || $asset->kind !== 'image') {
            return;
        }
        // SVGs deliberately bypass Imgix, so there is nothing to purge.
        if (strtolower((string)$asset->getExtension()) === 'svg') {
            return;
        }

        foreach (array_keys($settings->sources) as $sourceName) {
            try {
                [, $source] = Plugin::getInstance()->urls->sourceConfig($sourceName);
                if (($source['apiKey'] ?? '') === '') {
                    continue;
                }
                $url = Plugin::getInstance()->urls->originalUrl($asset, $sourceName);
                $cacheKey = 'imgixkit-purge-' . hash('sha256', $sourceName . '|' . $url);
                if (Craft::$app->getCache()->get($cacheKey)) {
                    continue;
                }
                Craft::$app->getCache()->set($cacheKey, true, 60);
                Craft::$app->getQueue()->push(new PurgeJob([
                    'url' => $url,
                    'source' => $sourceName,
                ]));
            } catch (\Throwable $exception) {
                Craft::warning([
                    'message' => $exception->getMessage(),
                    'assetId' => $asset->id,
                    'source' => $sourceName,
                ], 'imgixkit');
            }
        }
    }

    public function purge(string $url, string $sourceName): void
    {
        [, $source] = Plugin::getInstance()->urls->sourceConfig($sourceName);
        $apiKey = (string)($source['apiKey'] ?? '');
        if ($apiKey === '') {
            Craft::warning("Imgix purge skipped for source $sourceName: API key missing.", 'imgixkit');
            return;
        }

        // Strip the signature: it does not belong in a purge request, let alone in a log.
        $url = strtok($url, '?') ?: $url;
        $response = Craft::createGuzzleClient([
            'timeout' => 15,
            'connect_timeout' => 5,
            // Off, so the status code below is actually evaluated instead of
            // Guzzle turning it into an exception first.
            'http_errors' => false,
        ])->post(self::ENDPOINT, [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/vnd.api+json',
                'Content-Type' => 'application/vnd.api+json',
            ],
            'json' => [
                'data' => [
                    'type' => 'purges',
                    'attributes' => ['url' => $url],
                ],
            ],
        ]);

        $status = $response->getStatusCode();
        if ($status >= 200 && $status < 300) {
            return;
        }

        // Imgix did not know this image, for instance because no variant of it
        // was ever requested. Nothing to purge then.
        if ($status === 404) {
            Craft::info("Imgix purge skipped for source $sourceName: image not in cache.", 'imgixkit');
            return;
        }

        $body = trim((string)$response->getBody());
        throw new ImgixException(sprintf(
            'Imgix purge failed with HTTP %d: %s',
            $status,
            $body === '' ? '(empty response)' : mb_substr($body, 0, 500),
        ));
    }
}
