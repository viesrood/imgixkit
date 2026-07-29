<?php

namespace viesrood\imgixkit\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use viesrood\imgixkit\Plugin;
use yii\console\ExitCode;

final class DiagnoseController extends Controller
{
    public $defaultAction = 'index';

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
}
