<?php

namespace viesrood\imgixkit\jobs;

use Craft;
use craft\queue\BaseJob;
use craft\queue\QueueInterface;
use viesrood\imgixkit\Plugin;
use yii\queue\RetryableJobInterface;

final class PurgeJob extends BaseJob implements RetryableJobInterface
{
    public string $url = '';
    public string $source = 'default';

    private const MAX_ATTEMPTS = 3;

    public function execute($queue): void
    {
        Plugin::getInstance()->purge->purge($this->url, $this->source);
        if ($queue instanceof QueueInterface) {
            $this->setProgress($queue, 1);
        }
    }

    public function getTtr(): int
    {
        return 60;
    }

    /**
     * A structural problem (expired API key, revoked source) does not fix
     * itself by retrying forever.
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('imgixkit', 'Purge Imgix cache');
    }
}
