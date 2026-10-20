# Release Notes for Schedule-it

## 2.1.0 - 2026-10-20

### Added
- `Job::exec('<shell line>')`, or a list entry with `exec` in place of `command`, runs a shell command from the project root with the same log, output, lock and exit-code handling as a console command. ([#2](https://github.com/webdna/schedule-it/issues/2))
- `->background()`, or `'background' => true`, runs a job as its own process (`schedule-it/run/job`) so the jobs after it do not wait. It takes the job's lock itself and writes its output and result to the log file. ([#1](https://github.com/webdna/schedule-it/issues/1))
- `schedule-it/list` shows the command beneath a job's description, each job's next run in Craft's timezone as well as its own, and whether it runs in turn or in the background. ([#2](https://github.com/webdna/schedule-it/issues/2))

### Changed
- Each job's lock is taken just before it runs and released as soon as it ends. It used to be taken for every due job before the first one ran, so one long job held back every other job due with it, and runners in later minutes skipped them. ([#1](https://github.com/webdna/schedule-it/issues/1))
- The minute a job was started is recorded in Craft's cache, so a second runner in the same minute skips a job the first has already run.
- `schedule-it/run` reports a skipped job (`– … skipped: …`) instead of leaving it out.
- Job output is read from the job's process as it is written, so two runners sharing a log file never print each other's output.
- The library's `withoutOverlapping()` and `onOneServer()` do nothing on a job (every job is already locked), and a `skip()` added in `configure` no longer replaces the lock.
- Expressions stay in UTC by default.

## 2.0.1 - 2026-10-19

### Changed
- Plugin Store metadata: developer URL, changelog URL, authors and an icon.
- Licence file renamed to `LICENSE.txt` (MIT).

## 2.0.0 - 2026-10-02
- Renamed from `webdna/scheduler` (handle `scheduler`) to `webdna/schedule-it` (handle `schedule-it`). The Plugin Store already lists Verbb's Scheduler under the handle `scheduler`, and Craft's update check matches plugins by handle, so every install was offered that plugin's updates in the control panel.
- The schedule moves to `config/schedule-it.php`, the commands to `schedule-it/run` and `schedule-it/list`, the namespace to `webdna\\scheduleit`, and the default log file to `storage/logs/schedule-it.log`. The plugin keeps nothing in the database, so moving is uninstall the old, install the new — see the README.
- `schedule-it/run` and `schedule-it/list` refuse to start while the schedule is still in `config/scheduler.php` and `config/schedule-it.php` does not exist, rather than running nothing.
- `craft scheduler/run` fails with a pointer to `schedule-it/run` once the old plugin is gone.

## 1.0.1 - 2026-09-30
- `craft schedule/run` now fails with a pointer to `scheduler/run`. The underlying library registers `schedule/run` on every console app through Yii's extension bootstrap, where it ran an empty schedule and exited 0 — so a cron entry left on the old command reported success every minute while running nothing. A `schedule` command a project configured itself is left alone.

## 1.0.0 - 2026-09-30
- Initial release: the schedule in `config/scheduler.php`, run by one `scheduler/run` cron entry.
- Jobs can be written in words — `Job::command('gc/run')->dailyAt('03:00')`, `->weekdays()->at('08:30')` — or as cron expressions, side by side.
- Jobs default to UTC, a cross-server lock, stdout and stderr in the log file, and replay of that output to the runner's stdout.
- `scheduler/run` reports each job's exit code and exits non-zero if any failed, and refuses to start on a file or null mutex unless `singleServer` is set.
- `scheduler/list` shows every job and its next due time.
- A misspelt key, bad expression or unknown timezone in the schedule is an error, never a job that silently does not run.
