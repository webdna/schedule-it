# Release Notes for Scheduler

## 1.0.0 30/09/26
- Initial release: the schedule in `config/scheduler.php`, run by one `scheduler/run` cron entry.
- Jobs can be written in words — `Job::command('gc/run')->dailyAt('03:00')`, `->weekdays()->at('08:30')` — or as cron expressions, side by side.
- Jobs default to UTC, a cross-server lock, stdout and stderr in the log file, and replay of that output to the runner's stdout.
- `scheduler/run` reports each job's exit code and exits non-zero if any failed, and refuses to start on a file or null mutex unless `singleServer` is set.
- `scheduler/list` shows every job and its next due time.
- A misspelt key, bad expression or unknown timezone in the schedule is an error, never a job that silently does not run.
