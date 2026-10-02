<?php

namespace webdna\scheduleit\console\controllers;

use craft\console\Controller;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Answers a command the schedule used to run under: `craft schedule/run` and `craft scheduler/run`.
 *
 * `schedule` is the library's. omnilight/yii2-scheduling registers it on every console app
 * through Yii's extension bootstrap, which Craft runs. With no schedule file it runs an
 * empty schedule and exits 0, so a host entry left on `schedule/run` after a move to this
 * plugin would report success every minute while running nothing.
 *
 * `scheduler` is this plugin's own, before 2.0.0 renamed it to `schedule-it`.
 *
 * Both fail here, saying where the schedule went.
 */
class MovedController extends Controller
{
    public $defaultAction = 'run';

    public function actionRun(): int
    {
        $this->stderr(
            "craft {$this->id}/run runs nothing here. The schedule is config/schedule-it.php and its command is `craft schedule-it/run`\n"
            . "— change this cron entry to that.\n",
            Console::FG_RED,
        );

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
