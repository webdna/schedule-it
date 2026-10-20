<?php

namespace webdna\scheduleit;

use omnilight\scheduling\Schedule as BaseSchedule;
use yii\base\Application;
use yii\caching\CacheInterface;

/**
 * The schedule, with the defaults every job needs already applied.
 *
 * @phpstan-import-type JobSpec from JobConfig
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
     * Jobs switched off with `enabled => false`, kept so `schedule-it/list` can show them.
     *
     * @var list<JobSpec>
     */
    public array $disabled = [];

    /**
     * Where each job's last start minute is kept (see Event::$cache).
     */
    public ?CacheInterface $cache = null;

    /** @var (\Closure(string): void)|null */
    private ?\Closure $write = null;

    /**
     * Echoes each job's output through `$write` as the job writes it, besides the log file.
     *
     * @param callable(string): void $write
     */
    public function echoOutputTo(callable $write): void
    {
        $this->write = \Closure::fromCallable($write);
    }

    /**
     * Adds a console command on a cron expression, with the defaults:
     *
     *  - read in this schedule's timezone (UTC unless configured);
     *  - locked while it runs, across servers, through the app's mutex (see Event);
     *  - stdout and stderr appended to the log file and echoed to the runner's stdout;
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
        $event->route = $command;

        return $this->withDefaults($event, $cron, $command);
    }

    /**
     * Adds a shell command on a cron expression, run from the project root, with the same
     * defaults as job().
     *
     * Wrapped in a subshell so the log redirect covers the whole line: without it,
     * `a && b >> log` would log only `b`.
     */
    public function execJob(string $line, string $cron): Event
    {
        /** @var Event $event */
        $event = $this->exec('(' . $line . ')');
        $event->shellLine = $line;

        return $this->withDefaults($event, $cron, $line);
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
        $event->cache = $this->cache;
        $event->craftScript = $this->cliScriptName;
        $event->logFile = $this->logFile ?? null;
        $event->write = $this->write;

        return $event;
    }

    /**
     * Every job is checked against the same moment, so one that is slow to check cannot
     * push the rest into the next minute.
     */
    public function dueEvents(Application $app)
    {
        $now = new \DateTimeImmutable('now');

        foreach ($this->_events as $event) {
            if ($event instanceof Event) {
                $event->dueAt = $now;
            }
        }

        return parent::dueEvents($app);
    }

    private function withDefaults(Event $event, string $cron, string $description): Event
    {
        return $event->cron($cron)
            ->timezone($this->timezone)
            ->description($description);
    }
}
