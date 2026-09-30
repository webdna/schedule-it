# Scheduler for Craft CMS

The site's scheduled jobs, kept in the repository. The host runs **one** cron entry every
minute, and that entry works out which jobs are due and runs them:

```
* * * * *  php craft scheduler/run
```

The schedule itself is `config/scheduler.php`, so it is reviewed like any other code, it is
the same on every environment that deploys the commit, and a new environment gets every job
the moment that one entry exists — instead of jobs living only in a hosting dashboard,
invisible to the repository and missing wherever someone forgot to re-add them.

Built on [omnilight/yii2-scheduling](https://github.com/omnilight/yii2-scheduling), with the
defaults that library needs to work under Craft already applied.

## Requirements

Craft CMS 5, PHP 8.2+. A shared mutex (Craft 5's default database mutex is one) unless the
host can only ever run one server — see [Overlap](#overlap).

## Install

```
composer require webdna/scheduler
php craft plugin/install scheduler
```

Copy `vendor/webdna/scheduler/src/config.php` to `config/scheduler.php`, add the jobs,
then add the one cron entry on each environment (see [Servd](#servd) for that host).

## The schedule

```php
<?php
// config/scheduler.php
use craft\helpers\App;

return [
    'jobs' => [
        // The console command, arguments included => a cron expression.
        'gc/run' => '0 3 * * *',
        'my-module/listings/archive --dryRun=0' => '30 2 * * *',

        // Or an array, for a description, a timezone, or a per-environment switch.
        'my-module/reminders/send' => [
            'cron' => '0 8 * * *',
            'description' => 'Membership reminders — sends real email',
            'enabled' => App::env('CRAFT_ENVIRONMENT') === 'production',
        ],

        // One command on two schedules: list entries that name their command.
        ['command' => 'feeds/sync', 'cron' => '0 6 * * 1-5'],
        ['command' => 'feeds/sync', 'cron' => '0 12 * * 6,0'],
    ],
];
```

| Job key | |
|---|---|
| `cron` | Required. A five-field cron expression. |
| `timezone` | The timezone `cron` is read in. Defaults to the schedule's (`UTC`). |
| `description` | What `list` and the run output call the job. Defaults to the command. |
| `enabled` | `true`, `false`, or a string Craft reads as a boolean (`'$SCHEDULE_REMINDERS'`). A disabled job is shown by `list` and never run. |
| `command` | Only for list entries; otherwise the key is the command. |

**Anything the plugin cannot read is an error, not a skipped job.** A misspelt key
(`'enable' => false`), a bad expression or an unknown timezone stops `scheduler/run` with a
message and exit code 78, because the alternative is a job that silently never runs.

Top-level settings:

| Setting | Default | |
|---|---|---|
| `timezone` | `'UTC'` | Keep UTC. Otherwise expressions are read in PHP's timezone, which Craft sets from the site's, and every job moves an hour twice a year. |
| `logFile` | `storage/logs/scheduler.log` | Aliases and env vars are parsed. |
| `singleServer` | `false` | See [Overlap](#overlap). |
| `configure` | `null` | `function (\webdna\scheduler\Schedule $schedule) {}` — for anything the array cannot say. `$schedule->job('a/b', '0 * * * *')->weekdays()` gets every default below; the library's `call()`, `when()`, `skip()` and `thenPing()` are all there. |

**Never schedule `queue/run` or `queue/listen`.** The host or Craft already runs the queue,
and a second consumer runs jobs twice or takes them mid-flight. `queue/listen` never exits,
so it would also block every job after it. Leave `db/backup` to the host's backups too.

## Commands

**`php craft scheduler/run`** — runs every job due this minute, one after another, then
exits non-zero if any of them failed. Each job is reported as it ends:

```
Running scheduled command: gc/run
…the job's own output…
✓ gc/run — exit 0 in 1.4s
```

Most minutes nothing is due, and it prints `No scheduled commands are ready to run.` — that
is evidence the schedule loaded, not a problem.

**`php craft scheduler/list`** — every job, its expression, timezone and next due time, the
disabled ones, and whether the mutex would let `run` start. Runs nothing.

## What it does for you

Each of these is a way the library, used directly under Craft, fails without saying so:

- **It runs through `craft`.** The library spawns jobs through a script called `yii`, which a
  Craft project does not have — so nothing runs and nothing is reported. The path is also made
  absolute, because cron does not guarantee a working directory.
- **It keeps stderr.** Every job's stdout *and* stderr go to the log file; without that, a
  fatal in a job leaves no trace.
- **It replays job output to stdout.** See [Servd](#servd) — on a host whose task runner
  throws its filesystem away, the log file alone is worthless.
- **It reports exit codes.** The library discards them, so its runner exits 0 whatever
  happened.
- **It refuses a lock other servers cannot see.** See [Overlap](#overlap).
- **It is console-only.** The web app has no route to the runner; `/actions/scheduler/run` is
  a 404.

## Overlap

Every job takes a lock for as long as it runs, through Craft's `mutex` component, so a job
still running from the last minute is skipped rather than started twice — and on a host with
several app servers, only one of them runs it.

That only works if the lock is somewhere every server can see. Craft 5's default is the
database (`yii\mutex\MysqlMutex` / `PgsqlMutex`), which is. A `FileMutex` is not: it lives on
one server's disk. The library's own guard tests Craft's wrapper component rather than the
driver inside it, so it never catches this; the plugin checks the driver, and `scheduler/run`
refuses to start on a file (or null) mutex. On a host that can never run two servers, set
`'singleServer' => true` to run anyway.

## Servd

1. Servd dashboard → the project → the environment → **Scheduled Tasks**.
2. Add **one** task: `php craft scheduler/run`, every minute (`* * * * *`). Match the form
   Servd's existing tasks use.
3. Repeat per environment — **environments do not share tasks**. Nothing else is added there,
   ever; the jobs come from the repository.

Two things specific to Servd:

- **Servd already runs the queue and the backups.** Do not schedule `queue/*` or `db/backup`.
- **The log file does not survive.** A scheduled task runs in Servd's task runner, not the web
  container, with a filesystem that goes when the task ends; Servd collects stdout only. That
  is why the runner replays each job's output: read it in **Servd's log for the task**, and do
  not look for `storage/logs/scheduler.log` there. Locally the file is the thing to read.

**Think about staging before adding its task.** Once the entry exists, every job runs there,
including any that send email. Point staging's mailer somewhere harmless, give those jobs an
`enabled` switch, or leave staging without the task.

## Local

There is no local cron under DDEV. Run a job directly with `ddev craft <command>`, or the
runner itself with `ddev craft scheduler/run`, which runs whatever is due at that minute in
UTC. `ddev craft scheduler/list` shows when that is.

## Moving from a hand-wired `yii2-scheduling` setup

If the project already maps `schedule` to `omnilight\scheduling\ScheduleController` in
`config/app.console.php` with a `config/schedule.php` file:

1. Install the plugin, and move each job into `config/scheduler.php`. Chained
   `->cron()->timezone('UTC')->onOneServer()->appendOutputTo()->then(...)` lines become one
   array entry each.
2. Remove the `schedule` component and the `schedule` `controllerMap` entry, and delete
   `config/schedule.php`.
3. Change the host's one cron entry from `schedule/run` to `scheduler/run` **in the same
   deploy**. Until it changes, the old entry finds no command and nothing runs.
