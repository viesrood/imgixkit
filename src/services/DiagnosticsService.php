<?php

namespace viesrood\imgixkit\services;

use Craft;
use craft\base\Component;
use Throwable;
use viesrood\imgixkit\Plugin;

final class DiagnosticsService extends Component
{
    public function report(): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $checks = [];

        if (!isset($settings->sources[$settings->defaultSource])) {
            $checks[] = $this->check('error', Craft::t('imgixkit', 'Default source'), Craft::t('imgixkit', 'The default source does not exist.'));
        } else {
            $checks[] = $this->check('ok', Craft::t('imgixkit', 'Default source'), $settings->defaultSource);
        }

        // A typo in here would otherwise only surface on the first page load.
        try {
            Plugin::getInstance()->urls->validateParams($settings->defaultParams);
            $checks[] = $this->check(
                'ok',
                Craft::t('imgixkit', 'Default parameters'),
                $this->describeParams($settings->defaultParams),
            );
        } catch (Throwable $exception) {
            $checks[] = $this->check('error', Craft::t('imgixkit', 'Default parameters'), $exception->getMessage());
        }

        $volumeHandles = array_map(
            static fn($volume): string => $volume->handle,
            Craft::$app->getVolumes()->getAllVolumes(),
        );

        foreach ($settings->sources as $name => $source) {
            $domain = (string)($source['domain'] ?? '');
            $checks[] = $this->check(
                preg_match('/^(?:[a-z0-9-]+\.)+[a-z]{2,}$/i', $domain) ? 'ok' : 'error',
                Craft::t('imgixkit', 'Source: {name}', ['name' => $name]),
                $domain !== '' ? "https://$domain" : Craft::t('imgixkit', 'Domain is missing'),
            );

            $mapped = array_keys($source['volumeMap'] ?? []);
            // A handle that does not exist makes every asset in that volume
            // silently fall back to its Craft URL.
            $unknown = array_diff($mapped, $volumeHandles);
            $checks[] = $this->check(
                $mapped === [] || $unknown !== [] ? 'error' : 'ok',
                Craft::t('imgixkit', 'Volume mapping: {name}', ['name' => $name]),
                match (true) {
                    $mapped === [] => Craft::t('imgixkit', 'No volumes mapped'),
                    $unknown !== [] => Craft::t('imgixkit', 'Unknown volume handle: {handles}', [
                        'handles' => implode(', ', $unknown),
                    ]),
                    default => implode(', ', $mapped),
                },
            );

            $checks[] = $this->check(
                !empty($source['signingKey']) ? 'ok' : 'warning',
                Craft::t('imgixkit', 'URL signing: {name}', ['name' => $name]),
                !empty($source['signingKey']) ? Craft::t('imgixkit', 'Enabled') : Craft::t('imgixkit', 'Disabled'),
            );
            // Imgix retired its v1 API keys; those are short. A v1 key is
            // accepted by the config but every purge with it fails.
            $apiKey = (string)($source['apiKey'] ?? '');
            $legacyKey = $apiKey !== '' && strlen($apiKey) < 50;
            $checks[] = $this->check(
                match (true) {
                    $legacyKey => 'error',
                    $apiKey !== '' && $settings->autoPurge => 'ok',
                    default => 'warning',
                },
                Craft::t('imgixkit', 'Automatic purging: {name}', ['name' => $name]),
                match (true) {
                    $legacyKey => Craft::t('imgixkit', 'The API key looks like a retired v1 key. Generate a new one with purge permissions.'),
                    $apiKey !== '' && $settings->autoPurge => Craft::t('imgixkit', 'Enabled'),
                    default => Craft::t('imgixkit', 'Disabled'),
                },
            );
        }

        return [
            'checks' => $checks,
            'healthy' => !array_filter($checks, static fn(array $check): bool => $check['status'] === 'error'),
            'environment' => Craft::$app->getConfig()->getGeneral()->devMode ? 'development' : 'production',
        ];
    }

    private function check(string $status, string $label, string $detail): array
    {
        return compact('status', 'label', 'detail');
    }

    private function describeParams(array $params): string
    {
        if ($params === []) {
            return Craft::t('imgixkit', 'None');
        }
        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . (is_array($value) ? implode(',', $value) : (string)$value);
        }
        return implode(' · ', $parts);
    }
}
