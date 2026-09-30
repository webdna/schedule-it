<?php

namespace webdna\scheduler;

use omnilight\scheduling\Schedule as BaseSchedule;

/**
 * The schedule, with the defaults every job needs already applied.
 *
 * @phpstan-import-type Job from JobConfig
 */
class Schedule extends BaseSchedule
{
    /**
     * The timezone every expression is read in unless a job sets its own.
     */
    public string $timezone = 'UTC';

    /**
     * Where each job's output is appended.
     */
    public string $logFile;

    /**
     * Whether a file-based mutex is acceptable (see Settings::$singleServer).
     */
    public bool $singleServer = false;

    /**
     * Jobs switched off with `enabled => false`, kept so `scheduler/list` can show them.
     *
     * @var list<Job>
     */
    public array $disabled = [];

    private ?OutputReplay $replay = null;

    /**
     * Starts replaying job output through `$write`. Everything already in the log file is
     * behind the high-water mark, so only what this run's jobs write is echoed.
     *
     * @param callable(string): void $write
     */
    public function startReplay(callable $write): void
    {
        $this->replay = new OutputReplay($this->logFile, $write);
    }

    /**
     * Adds a console command on a cron expression, with the defaults:
     *
     *  - read in this schedule's timezone (UTC unless configured);
     *  - never overlapping itself, across servers, through the app's mutex;
     *  - stdout and stderr appended to the log file, then replayed to the runner's stdout;
     *  - described by its command, not by the full shell line.
     *
     * Chain anything else the library offers onto the returned event.
     *
     * @param string $command a console route with its arguments, e.g. `gc/run --silent`
     */
    public function job(string $command, string $cron): Event
    {
        /** @var Event $event */
        $event = $this->command($command);

        $event->cron($cron)
            ->timezone($this->timezone)
            ->description($command)
            ->appendOutputTo(escapeshellarg($this->logFile))
            // Badly named in the library: this does not discard errors, it appends 2>&1 so
            // stderr lands in the log beside stdout. Without it a job's fatal leaves no trace.
            ->omitErrors(true)
            // Not onOneServer(): its guard tests the Craft wrapper rather than the driver, so
            // it can never fire. `scheduler/run` checks the driver itself (MutexCheck) and
            // refuses to start, which is the only point a refusal is any use.
            ->withoutOverlapping();

        if ($this->replay !== null) {
            // then() is typed \Closure, so the invokable object has to be wrapped.
            $event->then(\Closure::fromCallable($this->replay));
        }

        return $event;
    }

    /**
     * Quotes the PHP binary and the script, which the library pastes in raw.
     */
    public function command($command)
    {
        return $this->exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($this->cliScriptName) . ' ' . $command);
    }

    public function exec($command)
    {
        $this->_events[] = $event = new Event($this->_mutex, $command);

        return $event;
    }
}
