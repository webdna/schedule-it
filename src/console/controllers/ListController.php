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
 * Shows the schedule: every job and its command, when it is next due in its own timezone and
 * in Craft's, and whether the runner would start.
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
        $localZone = Craft::$app->getTimeZone();

        $this->stdout('Mutex:    ' . $driverName . ($shared ? " (shared)\n" : " (NOT shared)\n"));
        $this->stdout("Timezone: {$schedule->timezone} (Craft's: {$localZone})\n");
        $this->stdout('Cache:    ' . ($schedule->cache !== null ? get_class($schedule->cache) : 'none') . " (each job's last start, so a second runner in the same minute does not repeat it)\n");
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
            $summary = $event->getSummaryForDisplay();
            $rows[] = [
                $summary,
                $event->getExpression(),
                $timezone,
                $next->format('D j M H:i'),
                $next->setTimezone(new \DateTimeZone($localZone))->format('D j M H:i'),
                $event instanceof Event && $event->isBackground() ? 'background' : 'in turn',
                'on',
            ];
            // The command is what an operator copies to run the job by hand, so it is shown
            // whenever the description hides it.
            if ($event instanceof Event && $summary !== ($event->route ?? $event->shellLine)) {
                $rows[] = $this->commandRow($event->getCommandForDisplay());
            }
        }
        foreach ($schedule->disabled as $job) {
            $rows[] = [$job['description'] ?? $job['command'], $job['cron'], $job['timezone'] ?? $schedule->timezone, '—', '—', $job['background'] ? 'background' : 'in turn', 'off'];
            if ($job['description'] !== null && $job['description'] !== $job['command']) {
                $rows[] = $this->commandRow($job['shell'] ? $job['command'] : 'craft ' . $job['command']);
            }
        }

        if ($rows === []) {
            $this->stdout("No jobs. Add them to config/schedule-it.php.\n");
            return ExitCode::OK;
        }

        $this->table(['Job', 'Cron', 'Timezone', 'Next due', "In {$localZone}", 'Runs', 'Enabled'], $rows, ['maxSize' => 100]);

        return ExitCode::OK;
    }

    /**
     * A row holding only the command, beneath its job.
     *
     * @return list<string|array<int|string, mixed>>
     */
    private function commandRow(string $command): array
    {
        return [['  ' . $command, 'format' => [Console::FG_GREY]], '', '', '', '', '', ''];
    }
}
