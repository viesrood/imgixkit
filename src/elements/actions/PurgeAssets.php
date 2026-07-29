<?php

namespace viesrood\imgixkit\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use viesrood\imgixkit\Plugin;

/**
 * Bulk action on the Assets index: queue an Imgix purge for the selected
 * assets. Useful when a file was changed outside Craft, or when Imgix still
 * holds a rendition you want gone right now.
 */
final class PurgeAssets extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('imgixkit', 'Purge from Imgix');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $purge = Plugin::getInstance()->purge;

        if (!$purge->isConfigured()) {
            $this->setMessage(Craft::t('imgixkit', 'No Imgix source has an API key, so nothing can be purged.'));
            return false;
        }

        $queued = 0;
        $skipped = 0;
        /** @var Asset $asset */
        foreach ($query->all() as $asset) {
            $jobs = $purge->enqueueAsset($asset, true);
            if ($jobs > 0) {
                $queued += $jobs;
            } else {
                $skipped++;
            }
        }

        if ($queued === 0) {
            $this->setMessage(Craft::t('imgixkit', 'Nothing to purge for this selection.'));
            return false;
        }

        $this->setMessage($skipped > 0
            ? Craft::t('imgixkit', '{count} purge job(s) queued, {skipped} asset(s) skipped.', [
                'count' => $queued,
                'skipped' => $skipped,
            ])
            : Craft::t('imgixkit', '{count} purge job(s) queued.', ['count' => $queued]));

        return true;
    }
}
