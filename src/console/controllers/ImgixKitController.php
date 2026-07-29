<?php

namespace viesrood\imgixkit\console\controllers;

use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use viesrood\imgixkit\Plugin;
use yii\console\ExitCode;

/**
 * php craft imgixkit          diagnostics
 * php craft imgixkit/purge --asset-id=123
 * php craft imgixkit/purge --volume=images
 */
final class ImgixKitController extends Controller
{
    public $defaultAction = 'index';

    // Named assetId rather than id because Yii's Controller already owns $id.
    /** @var int|null Purge a single asset by ID. */
    public ?int $assetId = null;

    /** @var string|null Purge every image in a volume, by handle. */
    public ?string $volume = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'purge' => array_merge(parent::options($actionID), ['assetId', 'volume']),
            default => parent::options($actionID),
        };
    }

    public function actionIndex(): int
    {
        $report = Plugin::getInstance()->diagnostics->report();
        foreach ($report['checks'] as $check) {
            $color = match ($check['status']) {
                'ok' => Console::FG_GREEN,
                'warning' => Console::FG_YELLOW,
                default => Console::FG_RED,
            };
            $this->stdout(strtoupper($check['status']), $color);
            $this->stdout("  {$check['label']}: {$check['detail']}\n");
        }
        return $report['healthy'] ? ExitCode::OK : ExitCode::CONFIG;
    }

    /**
     * Queues Imgix purge jobs. Requires --id or --volume: purging the whole
     * library by accident is expensive and slow to undo.
     */
    public function actionPurge(): int
    {
        $purge = Plugin::getInstance()->purge;

        if (!$purge->isConfigured()) {
            $this->stderr("No Imgix source has an API key, so nothing can be purged.\n", Console::FG_RED);
            return ExitCode::CONFIG;
        }

        if ($this->assetId === null && $this->volume === null) {
            $this->stderr("Specify --asset-id=<id> or --volume=<handle>.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $query = Asset::find()->kind('image');
        if ($this->assetId !== null) {
            $query->id($this->assetId);
        }
        if ($this->volume !== null) {
            $query->volume($this->volume);
        }

        $assets = $query->all();
        if ($assets === []) {
            $this->stderr("No matching image assets found.\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($assets as $asset) {
            $jobs = $purge->enqueueAsset($asset, true);
            $jobs > 0 ? $queued += $jobs : $skipped++;
        }

        $this->stdout("Queued $queued purge job(s)", Console::FG_GREEN);
        $this->stdout($skipped > 0 ? ", skipped $skipped asset(s).\n" : ".\n");
        $this->stdout("Run `php craft queue/run` if no queue daemon is running.\n");

        return ExitCode::OK;
    }
}
