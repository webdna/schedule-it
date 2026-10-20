<?php

namespace webdna\scheduleit\console\controllers;

use Craft;
use craft\console\Controller;
use omnilight\scheduling\Event as BaseEvent;
use webdna\scheduleit\Event;
use webdna\scheduleit\MutexCheck;
use webdna\scheduleit\Schedule;
use webdna\scheduleit\ScheduleIt;
use yii\base\InvalidConfigException;
use yii\console\ExitCode;
use yii\helpers\Console;

/**
 * Runs the jobs that are due this minute.
 *
 * The host's one cron entry: `* * * * * craft schedule-it/run`.
 */
class RunController extends Controller
{
    /**
     * Runs every job due this minute, one after another, and exits non-zero if any failed.
     */
    public function actionIndex(): int
    {
        $schedule = $this->schedule(function(string $output): void {
            $this->stdout($output);
        });
        if (!$schedule instanceof Schedule) {
            return $schedule;
        }

        // Only the expressions and filters are read here. Each job's lock is taken when its
        // turn comes, so a job another runner is still running holds back only itself.
        $events = $schedule->dueEvents(Craft::$app);

        if ($events === []) {
            $this->stdout("No scheduled commands are ready to run.\n");
            return ExitCode::OK;
        }

        $failed = 0;

        foreach ($events as $event) {
            $failed += $this->runOne($event, $schedule) ? 0 : 1;
        }

        // Written to stdout, not stderr: stdout is what a host like Servd shows as the
        // task's log, and the exit code is what marks the run as failed.
        if ($failed > 0) {
            $this->stdout(sprintf("%d of %d scheduled command%s failed.\n", $failed, count($events), count($events) === 1 ? '' : 's'), Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        return ExitCode::OK;
    }

    /**
     * Runs one job now, by its id, for the given UTC minute (`YmdHi`): what a background job
     * is started as. It takes the job's lock and writes its result line like the runner,
     * and exits with the job's exit code.
     */
    public function actionJob(string $id, string $minute): int
    {
        // This process's stdout is already the log file, and the job writes its output
        // there itself, so echoing it too would log it twice.
        $schedule = $this->schedule(static function(): void {
        });
        if (!$schedule instanceof Schedule) {
            return $schedule;
        }

        foreach ($schedule->getEvents() as $event) {
            if ($event instanceof Event && $event->getId() === $id) {
                $event->background(false);
                $event->dueAt = \DateTimeImmutable::createFromFormat('YmdHi', $minute, new \DateTimeZone('UTC')) ?: null;
                $this->runOne($event, $schedule);

                return $event->exitCode ?? ExitCode::OK;
            }
        }

        $this->stderr("schedule-it: no job with id {$id} in config/schedule-it.php; it may have changed since this process was started.\n", Console::FG_RED);
        return ExitCode::CONFIG;
    }

    /**
     * The schedule, or the exit code when it must not run.
     *
     * @param callable(string): void $write
     */
    private function schedule(callable $write): Schedule|int
    {
        try {
            $schedule = ScheduleIt::getInstance()->buildSchedule($write);
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage() . PHP_EOL, Console::FG_RED);
            return ExitCode::CONFIG;
        }

        $driver = MutexCheck::driver(Craft::$app->has('mutex') ? Craft::$app->getMutex() : null);
        if (!$schedule->singleServer && !MutexCheck::isShared($driver)) {
            $this->stderr(sprintf(
                "schedule-it: refusing to run. The mutex driver is %s, which another server cannot see, so two servers could run the same job at once.\n"
                . "Use a database or Redis mutex, or set 'singleServer' => true in config/schedule-it.php if this host can never run two.\n",
                $driver !== null ? get_class($driver) : 'missing',
            ), Console::FG_RED);
            return ExitCode::CONFIG;
        }

        return $schedule;
    }

    /**
     * Runs one job and reports how it ended. False only when it ran and failed.
     */
    private function runOne(BaseEvent $event, Schedule $schedule): bool
    {
        $summary = $event->getSummaryForDisplay();

        // Announced once the job has its lock, so a skipped job is one line, not two.
        $event->on(BaseEvent::EVENT_BEFORE_RUN, function() use ($summary): void {
            $this->stdout("Running scheduled command: {$summary}\n");
        });

        $event->run(Craft::$app);

        if (!$event instanceof Event) {
            return true;
        }

        if ($event->skipped !== null) {
            $this->stdout(sprintf(
                "– %s — skipped: %s\n",
                $summary,
                $event->skipped === Event::SKIPPED_RUNNING
                    ? 'still running from an earlier start'
                    : 'already started for this minute by another runner',
            ), Console::FG_YELLOW);
            return true;
        }

        if ($event->isBackground()) {
            $event->pid !== null
                ? $this->stdout("↗ {$summary} — started in the background (pid {$event->pid}); its output goes to {$schedule->logFile}\n")
                : $this->stdout("✗ {$summary} — could not be started in the background\n", Console::FG_RED);
            return $event->pid !== null;
        }

        if ($event->exitCode === null) {
            return true;
        }

        $line = sprintf('%s %s — exit %d in %.1fs', $event->failed() ? '✗' : '✓', $summary, $event->exitCode, $event->duration);
        $event->failed()
            ? $this->stdout($line . PHP_EOL, Console::FG_RED)
            : $this->stdout($line . PHP_EOL);

        return !$event->failed();
    }
}
