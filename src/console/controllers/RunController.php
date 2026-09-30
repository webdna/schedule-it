<?php

namespace webdna\scheduler\console\controllers;

use Craft;
use craft\console\Controller;
use webdna\scheduler\Event;
use webdna\scheduler\MutexCheck;
use webdna\scheduler\Scheduler;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Runs the jobs that are due this minute.
 *
 * The host's one cron entry: `* * * * * craft scheduler/run`.
 */
class RunController extends Controller
{
    /**
     * Runs every job due this minute, one after another, and exits non-zero if any failed.
     */
    public function actionIndex(): int
    {
        $plugin = Scheduler::getInstance();

        try {
            $schedule = $plugin->buildSchedule(function(string $output): void {
                $this->stdout($output);
            });
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $driver = MutexCheck::driver(Craft::$app->has('mutex') ? Craft::$app->getMutex() : null);
        if (!$schedule->singleServer && !MutexCheck::isShared($driver)) {
            $this->stderr(sprintf(
                "scheduler: refusing to run. The mutex driver is %s, which another server cannot see, so two servers could run the same job at once.\n"
                . "Use a database or Redis mutex, or set 'singleServer' => true in config/scheduler.php if this host can never run two.\n",
                $driver !== null ? get_class($driver) : 'missing',
            ), Console::FG_RED);
            return ExitCode::CONFIG;
        }

        // Due-ness is checked for every job up front, and checking takes each job's lock —
        // so a job another server is already running is skipped here, silently.
        $events = $schedule->dueEvents(Craft::$app);

        if ($events === []) {
            $this->stdout("No scheduled commands are ready to run.\n");
            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($events as $event) {
            $summary = $event->getSummaryForDisplay();
            $this->stdout("Running scheduled command: {$summary}\n");

            $event->run(Craft::$app);

            if ($event instanceof Event && $event->exitCode !== null) {
                $line = sprintf('%s %s — exit %d in %.1fs', $event->failed() ? '✗' : '✓', $summary, $event->exitCode, $event->duration);
                $event->failed()
                    ? $this->stdout($line . PHP_EOL, Console::FG_RED)
                    : $this->stdout($line . PHP_EOL);
                $failed += $event->failed() ? 1 : 0;
            }
        }

        // Written to stdout, not stderr: stdout is what a host like Servd shows as the
        // task's log, and the exit code is what marks the run as failed.
        if ($failed > 0) {
            $this->stdout(sprintf("%d of %d scheduled command%s failed.\n", $failed, count($events), count($events) === 1 ? '' : 's'), Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }
}
