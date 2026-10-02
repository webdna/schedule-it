<?php

namespace webdna\scheduleit\console\controllers;

use Craft;
use craft\console\Controller;
use Cron\CronExpression;
use webdna\scheduleit\Event;
use webdna\scheduleit\MutexCheck;
use webdna\scheduleit\ScheduleIt;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Shows the schedule: every job, when it is next due, and whether the runner would start.
 */
class ListController extends Controller
{
    /**
     * Lists every job and when it next runs. Runs nothing.
     */
    public function actionIndex(): int
    {
        $plugin = ScheduleIt::getInstance();

        try {
            $schedule = $plugin->buildSchedule(static function(): void {
            });
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $driver = MutexCheck::driver(Craft::$app->has('mutex') ? Craft::$app->getMutex() : null);
        $shared = MutexCheck::isShared($driver);
        $driverName = $driver !== null ? get_class($driver) : 'missing';

        $this->stdout('Mutex:    ' . $driverName . ($shared ? " (shared)\n" : " (NOT shared)\n"));
        $this->stdout("Timezone: {$schedule->timezone}\n");
        $this->stdout("Log file: {$schedule->logFile}\n");
        if (!$shared) {
            $schedule->singleServer
                ? $this->stdout("Running anyway: singleServer is true.\n", Console::FG_YELLOW)
                : $this->stdout("schedule-it/run will refuse to start until the mutex is shared or singleServer is set.\n", Console::FG_RED);
        }
        $this->stdout(PHP_EOL);

        $rows = [];
        foreach ($schedule->getEvents() as $event) {
            $timezone = $event instanceof Event ? ($event->getTimezone() ?? 'UTC') : date_default_timezone_get();
            $next = (new CronExpression($event->getExpression()))
                ->getNextRunDate(new \DateTime('now', new \DateTimeZone($timezone)));
            $rows[] = [$event->getSummaryForDisplay(), $event->getExpression(), $timezone, $next->format('D j M H:i'), 'on'];
        }
        foreach ($schedule->disabled as $job) {
            $rows[] = [$job['description'] ?? $job['command'], $job['cron'], $job['timezone'] ?? $schedule->timezone, '—', 'off'];
        }

        if ($rows === []) {
            $this->stdout("No jobs. Add them to config/schedule-it.php.\n");
            return ExitCode::OK;
        }

        $this->table(['Job', 'Cron', 'Timezone', 'Next due', 'Enabled'], $rows);

        return ExitCode::OK;
    }
}
