<?php

namespace webdna\scheduler\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Answers `craft schedule/run` — the library's command, not this plugin's.
 *
 * omnilight/yii2-scheduling registers `schedule` on every console app through Yii's
 * extension bootstrap, which Craft runs. With no schedule file it runs an empty schedule
 * and exits 0, so a host entry left on `schedule/run` after a move to this plugin would
 * report success every minute while running nothing. This makes it fail instead.
 */
class MovedController extends Controller
{
    public $defaultAction = 'run';

    public function actionRun(): int
    {
        $this->stderr(
            "craft schedule/run runs nothing here. The schedule is config/scheduler.php and its command is `craft scheduler/run`\n"
            . "— change this cron entry to that.\n",
            Console::FG_RED,
        );

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
