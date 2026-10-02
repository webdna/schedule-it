# Release Notes for Schedule-it

## 2.0.0 02/10/26
- Renamed from `webdna/scheduler` (handle `scheduler`) to `webdna/schedule-it` (handle `schedule-it`). The Plugin Store already lists Verbb's Scheduler under the handle `scheduler`, and Craft's update check matches plugins by handle, so every install was offered that plugin's updates in the control panel.
- The schedule moves to `config/schedule-it.php`, the commands to `schedule-it/run` and `schedule-it/list`, the namespace to `webdna\\scheduleit`, and the default log file to `storage/logs/schedule-it.log`. The plugin keeps nothing in the database, so moving is uninstall the old, install the new — see the README.
- `schedule-it/run` and `schedule-it/list` refuse to start while the schedule is still in `config/scheduler.php` and `config/schedule-it.php` does not exist, rather than running nothing.
- `craft scheduler/run` fails with a pointer to `schedule-it/run` once the old plugin is gone.

## 1.0.1 30/09/26
- `craft schedule/run` now fails with a pointer to `scheduler/run`. The underlying library registers `schedule/run` on every console app through Yii's extension bootstrap, where it ran an empty schedule and exited 0 — so a cron entry left on the old command reported success every minute while running nothing. A `schedule` command a project configured itself is left alone.

## 1.0.0 30/09/26
- Initial release: the schedule in `config/scheduler.php`, run by one `scheduler/run` cron entry.
- Jobs can be written in words — `Job::command('gc/run')->dailyAt('03:00')`, `->weekdays()->at('08:30')` — or as cron expressions, side by side.
- Jobs default to UTC, a cross-server lock, stdout and stderr in the log file, and replay of that output to the runner's stdout.
- `scheduler/run` reports each job's exit code and exits non-zero if any failed, and refuses to start on a file or null mutex unless `singleServer` is set.
- `scheduler/list` shows every job and its next due time.
- A misspelt key, bad expression or unknown timezone in the schedule is an error, never a job that silently does not run.
