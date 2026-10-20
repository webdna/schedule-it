<?php

namespace webdna\scheduleit;

use Craft;
use Cron\CronExpression;
use omnilight\scheduling\Event as BaseEvent;
use Symfony\Component\Process\Process;
use yii\base\Application;
use yii\caching\CacheInterface;

/**
 * A scheduled command that locks itself only while it runs, and remembers how it ended.
 *
 * The library locks a job inside its `skip()` filter, which is read while the runner works
 * out what is due — so every due job was locked before the first one started, and one long
 * job held back all the others. Here the lock is taken in run(), just before the command
 * starts, and released as soon as it ends.
 *
 * The library also throws the exit code away, so its runner reports success whatever a job
 * did. This keeps the code and the time taken, and runs in the foreground from the project
 * root — the library would background any job without an after-callback. A job is
 * backgrounded only when it asks to be.
 *
 * A job's output is read from its own process, as it is written, and goes to two places:
 * the log file, and the runner's stdout (`$write`). The file alone is worthless on a host
 * like Servd, whose task runner throws its filesystem away and collects stdout only. Reading
 * the process rather than the file keeps each runner's stdout to its own jobs, when two
 * runners on one machine share the log.
 */
class Event extends BaseEvent
{
    /** The job's lock was held: it is still running from an earlier start. */
    public const SKIPPED_RUNNING = 'running';

    /** Another runner already started it for this minute, or a later one. */
    public const SKIPPED_STARTED = 'started';

    public ?int $exitCode = null;

    public ?float $duration = null;

    /**
     * Why the job did not run, when it did not: one of the SKIPPED_ constants.
     */
    public ?string $skipped = null;

    /**
     * The process a background job was started as.
     */
    public ?int $pid = null;

    /**
     * The console route, for a job added with Schedule::job().
     */
    public ?string $route = null;

    /**
     * The shell line as written, for a job added with Schedule::execJob().
     */
    public ?string $shellLine = null;

    /**
     * The moment the runner started; due-ness and the start record are read against it.
     */
    public ?\DateTimeImmutable $dueAt = null;

    /**
     * Where each job's last start minute is kept, so a second runner in the same minute
     * does not repeat it. Without one, only the lock applies.
     */
    public ?CacheInterface $cache = null;

    /**
     * The working directory. Defaults to the project root.
     */
    public ?string $cwd = null;

    /**
     * What a background job is started through, and where its output goes.
     */
    public ?string $craftScript = null;
    public ?string $logFile = null;

    /**
     * Where each chunk of the job's output is echoed, besides the log file.
     *
     * @var (\Closure(string): void)|null
     */
    public ?\Closure $write = null;

    private bool $background = false;

    public function run(Application $app)
    {
        if ($this->background) {
            $this->pid = $this->spawn();
            return;
        }

        if (!$this->_mutex->acquire($this->mutexName())) {
            $this->skipped = self::SKIPPED_RUNNING;
            return;
        }

        try {
            if (!$this->recordStart()) {
                $this->skipped = self::SKIPPED_STARTED;
                return;
            }

            $this->trigger(self::EVENT_BEFORE_RUN);

            $start = microtime(true);
            $this->exitCode = $this->runProcess();
            $this->duration = microtime(true) - $start;

            $this->callAfterCallbacks($app);
            $this->trigger(self::EVENT_AFTER_RUN);
        } finally {
            $this->_mutex->release($this->mutexName());
        }
    }

    /**
     * Runs the job as its own process, which the runner starts and does not wait for.
     */
    public function background(bool $background = true): static
    {
        $this->background = $background;

        return $this;
    }

    public function isBackground(): bool
    {
        return $this->background;
    }

    /**
     * The job's identity across servers and processes: its expression and what it runs,
     * with no path in it. Names the lock, the start record and a background process.
     */
    public function getId(): string
    {
        return substr(sha1($this->_expression . ' ' . ($this->route ?? $this->shellLine ?? $this->command)), 0, 12);
    }

    /**
     * What an operator would type to run the job by hand.
     */
    public function getCommandForDisplay(): string
    {
        return $this->route !== null ? 'craft ' . $this->route : ($this->shellLine ?? $this->command);
    }

    /**
     * Does nothing: every job is already locked while it runs. The library's version puts
     * the lock in the skip() filter, read while working out what is due, which is the
     * up-front locking this class exists to avoid.
     */
    public function withoutOverlapping()
    {
        return $this;
    }

    /**
     * Does nothing: the library's version only adds withoutOverlapping(). The shared-mutex
     * check it means to make is done by `schedule-it/run` (MutexCheck).
     */
    public function onOneServer()
    {
        return $this;
    }

    public function getTimezone(): ?string
    {
        $timezone = $this->_timezone;

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone;
    }

    public function failed(): bool
    {
        return $this->exitCode !== null && $this->exitCode !== 0;
    }

    /**
     * The minute this run is for, in UTC, as the start record and a background process
     * carry it.
     */
    public function dueMinute(): string
    {
        return ($this->dueAt ?? new \DateTimeImmutable('now'))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('YmdHi');
    }

    protected function mutexName()
    {
        return 'schedule-it/' . $this->getId();
    }

    protected function expressionPasses()
    {
        $date = \DateTime::createFromImmutable($this->dueAt ?? new \DateTimeImmutable('now'));
        if ($this->_timezone) {
            $date->setTimezone(is_string($this->_timezone) ? new \DateTimeZone($this->_timezone) : $this->_timezone);
        }

        return (new CronExpression($this->_expression))->isDue($date);
    }

    /**
     * Runs the command, sending its output to the log file and to `$write` as it arrives.
     */
    private function runProcess(): ?int
    {
        // Output the job was pointed at itself, with the library's sendOutputTo() or
        // appendOutputTo(), is left where it was sent.
        $captured = $this->_output === $this->getDefaultOutput();
        $line = $captured
            // 2>&1: stderr beside stdout, or a job's fatal leaves no trace.
            ? ($this->_user ? 'sudo -u ' . $this->_user . ' ' : '') . $this->command . ' 2>&1'
            : trim($this->buildCommand(), '& ');

        $process = Process::fromShellCommandline($line, $this->workingDirectory(), null, null, null);
        $log = $captured && $this->logFile !== null ? @fopen($this->logFile, 'ab') : false;

        try {
            $process->run(function(string $type, string $chunk) use ($log): void {
                if ($log !== false) {
                    fwrite($log, $chunk);
                }
                if ($this->write !== null) {
                    ($this->write)($chunk);
                }
            });
        } finally {
            if ($log !== false) {
                fclose($log);
            }
        }

        return $process->getExitCode();
    }

    /**
     * Records this minute as the job's last start, unless one this late is recorded
     * already. Called with the lock held, so two runners cannot both pass.
     */
    private function recordStart(): bool
    {
        if ($this->cache === null) {
            return true;
        }

        $key = 'schedule-it/started/' . $this->getId();
        $minute = $this->dueMinute();
        $last = $this->cache->get($key);

        // Fixed-width digits, so string order is time order.
        if (is_string($last) && strcmp($last, $minute) >= 0) {
            return false;
        }

        $this->cache->set($key, $minute, 86400);

        return true;
    }

    /**
     * Starts `schedule-it/run/job` detached, its output appended to the log, and returns
     * its process id.
     *
     * The lock is not taken here: a database lock belongs to this process's connection and
     * would go the moment the runner exits. The new process takes its own.
     */
    private function spawn(): ?int
    {
        if ($this->craftScript === null || $this->logFile === null) {
            return null;
        }

        $line = sprintf(
            'nohup %s %s schedule-it/run/job %s %s < /dev/null >> %s 2>&1 & echo $!',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($this->craftScript),
            $this->getId(),
            $this->dueMinute(),
            escapeshellarg($this->logFile),
        );

        $process = Process::fromShellCommandline($line, $this->workingDirectory(), null, null, 10);
        $process->run();
        $pid = trim($process->getOutput());

        return ctype_digit($pid) ? (int)$pid : null;
    }

    private function workingDirectory(): string
    {
        return $this->cwd ?? (string)Craft::getAlias('@root');
    }
}
