<?php

namespace webdna\scheduler\models;

use craft\base\Model;

/**
 * Scheduler settings, set in `config/scheduler.php` — never in the CP.
 */
class Settings extends Model
{
    /**
     * The jobs. Each is a `Job` written in words (`Job::command('gc/run')->dailyAt('03:00')`),
     * or keyed by the console command (arguments included) with either a cron expression or
     * an array of `cron`, and optionally `timezone`, `description` and `enabled`. See the README.
     *
     * @var array<int|string, string|array<string, mixed>|\webdna\scheduler\Job>
     */
    public array $jobs = [];

    /**
     * The timezone every cron expression is read in, unless a job sets its own.
     *
     * UTC, because otherwise the expression is evaluated in PHP's timezone, which Craft
     * sets from the site's — so the jobs would move by an hour twice a year.
     */
    public string $timezone = 'UTC';

    /**
     * Where job output is appended. Aliases and env vars are parsed. Defaults to
     * `storage/logs/scheduler.log`.
     */
    public ?string $logFile = null;

    /**
     * Set true only on a host that can never run two app servers. It lets the schedule run
     * with a file-based mutex, which stops a job overlapping itself on one machine but
     * cannot stop a second machine running it at the same instant.
     */
    public bool $singleServer = false;

    /**
     * Optional: `function (\webdna\scheduler\Schedule $schedule) {}`, called after the jobs
     * are added, for anything the array cannot say (`->weekdays()`, `->when()`, callbacks).
     *
     * Typed mixed rather than callable because it arrives from a config file; the plugin
     * refuses anything that is not callable when it builds the schedule.
     */
    public mixed $configure = null;
}
