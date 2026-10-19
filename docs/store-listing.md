# Plugin Store listing — draft

**Status:** draft by Claude, 19 Oct 2026. Sam owns the final text, the screenshots and the icon.
Nothing here is published until Sam submits it.

## Fields

| Field | Draft |
|---|---|
| Name | Schedule-it |
| Handle | `schedule-it` |
| Package | `webdna/schedule-it` |
| Short description | Keep your site's scheduled jobs in the repository: one cron entry runs them all. |
| Categories | Utilities · Developer Tools |
| Keywords | cron, schedule, scheduled tasks, jobs, console, servd |
| Edition | One edition, free |
| Licence | MIT |
| Requires | Craft CMS 5.0+, PHP 8.2+ |
| Documentation URL | https://github.com/webdna/schedule-it |
| Changelog URL | https://raw.githubusercontent.com/webdna/schedule-it/main/CHANGELOG.md |
| Repository | https://github.com/webdna/schedule-it |
| Icon | `src/icon.svg` (draft: a calendar holding a clock face — **Sam to approve or replace**) |

## Long description

> Schedule-it keeps your site's scheduled jobs in the repository. The host runs **one** cron
> entry every minute, and that entry works out which jobs are due and runs them:
>
> ```
> * * * * *  php craft schedule-it/run
> ```
>
> The schedule is `config/schedule-it.php`, so it is reviewed like any other code, it is the same
> on every environment that deploys the commit, and a new environment gets every job the moment
> that one entry exists — instead of jobs living only in a hosting dashboard, invisible to the
> repository and missing wherever someone forgot to re-add them.
>
> ### Write jobs in words or as cron expressions
>
> `Job::command('gc/run')->dailyAt('03:00')` and `->weekdays()->at('08:30')` sit beside plain
> cron expressions, with a description, a timezone or a per-environment switch where you need
> one. `craft schedule-it/list` shows every job, the expression it became and when it is next due.
>
> ### Failures are reported, never swallowed
>
> - Jobs run through `craft`, with an absolute path, because cron does not promise a working
>   directory.
> - Every job's stdout and stderr go to the log file, and are replayed to the runner's output for
>   hosts whose task runner throws its filesystem away.
> - `schedule-it/run` reports each job's exit code and exits non-zero if any failed.
> - A misspelt key, a bad expression or an unknown timezone is an error, never a job that
>   silently does not run.
> - It refuses a lock that other servers cannot see, so a job never runs twice at once across a
>   cluster.
>
> Console-only: the web app has no route to the runner. Built on
> [omnilight/yii2-scheduling](https://github.com/omnilight/yii2-scheduling), with the defaults
> that library needs under Craft already applied.

## Screenshots

From `_store-screenshots/shots/out/schedule-it/`:

1. `01-config.png` — a schedule in `config/schedule-it.php`, jobs in words beside cron expressions.
2. `02-list.png` — `craft schedule-it/list`: every job and when it is next due.
3. `03-run.png` — `craft schedule-it/run`: the jobs that ran and their exit codes.

## Before submitting

- [ ] Copy reviewed — the table and long description above
- [ ] Icon approved
- [ ] GitHub repo, Packagist and the Plugin Store plugin connected
- [ ] `2.0.1` tagged with a matching `CHANGELOG.md` heading
