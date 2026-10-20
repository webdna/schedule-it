<?php

namespace webdna\scheduleit;

use craft\helpers\App;
use Cron\CronExpression;
use yii\base\InvalidConfigException;

/**
 * Reads the `jobs` array of `config/schedule-it.php` into one shape, and refuses anything it
 * cannot read. A misspelt key or a bad expression is an error, never a job that silently
 * does not run: that is the failure a schedule is least likely to be caught in.
 *
 * A job's `command` is a console route; `shell` says it is a shell line instead, from `exec`.
 *
 * @phpstan-type JobSpec array{command: string, shell: bool, cron: string, timezone: ?string, description: ?string, enabled: bool, background: bool}
 */
final class JobConfig
{
    public const KEYS = ['command', 'exec', 'cron', 'timezone', 'description', 'enabled', 'background'];

    /**
     * @param array<int|string, mixed> $jobs
     * @return list<JobSpec>
     * @throws InvalidConfigException
     */
    public static function normalise(array $jobs): array
    {
        $normalised = [];

        foreach ($jobs as $key => $job) {
            $label = is_string($key) ? "job “{$key}”" : "job #{$key}";

            if (is_string($job)) {
                $job = ['cron' => $job];
            } elseif ($job instanceof Job) {
                if ($job->getProblem() !== null) {
                    throw new InvalidConfigException($job->getProblem());
                }
                // Checked below exactly as if the array had been written by hand.
                $job = $job->toArray();
            }
            if (!is_array($job)) {
                throw new InvalidConfigException("schedule-it: {$label} must be a cron expression, an array or a Job.");
            }

            $unknown = array_diff(array_keys($job), self::KEYS);
            if ($unknown !== []) {
                throw new InvalidConfigException(sprintf(
                    'schedule-it: %s has unknown key%s %s (allowed: %s).',
                    $label,
                    count($unknown) > 1 ? 's' : '',
                    implode(', ', $unknown),
                    implode(', ', self::KEYS),
                ));
            }

            $shell = array_key_exists('exec', $job);
            if ($shell) {
                if (is_string($key)) {
                    throw new InvalidConfigException("schedule-it: {$label} is keyed by a console command but sets `exec`. A shell job is a list entry: ['exec' => '…', 'cron' => '…'].");
                }
                if (array_key_exists('command', $job)) {
                    throw new InvalidConfigException("schedule-it: {$label} sets both `command` and `exec`; a job is one or the other.");
                }
                $command = $job['exec'];
                if (!is_string($command) || trim($command) === '') {
                    throw new InvalidConfigException("schedule-it: {$label} `exec` must be a shell command.");
                }
            } elseif (is_string($key)) {
                if (isset($job['command']) && $job['command'] !== $key) {
                    throw new InvalidConfigException("schedule-it: {$label} also sets a different `command`; use one or the other.");
                }
                $command = $key;
            } else {
                $command = $job['command'] ?? null;
            }
            if (!is_string($command) || trim($command) === '') {
                throw new InvalidConfigException("schedule-it: {$label} has no command. Key the job by its command, or set `command` (or `exec` for a shell command).");
            }
            $command = trim($command);
            $label = "job “{$command}”";

            $cron = $job['cron'] ?? null;
            if (!is_string($cron) || !CronExpression::isValidExpression($cron)) {
                throw new InvalidConfigException("schedule-it: {$label} needs a valid cron expression in `cron`, e.g. '0 3 * * *'.");
            }

            $timezone = isset($job['timezone']) ? self::timezone($job['timezone'], "{$label} `timezone`") : null;

            $description = $job['description'] ?? null;
            if ($description !== null && !is_string($description)) {
                throw new InvalidConfigException("schedule-it: {$label} `description` must be a string.");
            }

            $normalised[] = [
                'command' => $command,
                'shell' => $shell,
                'cron' => $cron,
                'timezone' => $timezone,
                'description' => $description,
                'enabled' => self::boolean($job['enabled'] ?? true, $label, 'enabled'),
                'background' => self::boolean($job['background'] ?? false, $label, 'background'),
            ];
        }

        return $normalised;
    }

    /**
     * @throws InvalidConfigException
     */
    public static function timezone(mixed $timezone, string $label): string
    {
        if (!is_string($timezone) || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            $shown = is_string($timezone) ? "'{$timezone}'" : get_debug_type($timezone);
            throw new InvalidConfigException("schedule-it: {$label} {$shown} is not a timezone identifier, e.g. 'UTC' or 'Europe/London'.");
        }

        return $timezone;
    }

    /**
     * A bool, or a string Craft reads as one — `'$SCHEDULER_REMINDERS'`, `'true'`, `'0'` —
     * so a job can be switched per environment from `.env`.
     *
     * @throws InvalidConfigException
     */
    private static function boolean(mixed $value, string $label, string $name): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $parsed = is_string($value) ? App::parseBooleanEnv($value) : null;
        if ($parsed === null) {
            throw new InvalidConfigException("schedule-it: {$label} `{$name}` must be a bool or an env var that reads as one.");
        }

        return $parsed;
    }
}
