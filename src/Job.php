<?php

namespace webdna\scheduler;

use Cron\CronExpression;

/**
 * A job described in words rather than a cron expression:
 *
 *     Job::command('gc/run')->dailyAt('03:00')
 *     Job::command('reports/send')->weekdays()->at('08:30')
 *     Job::command('feeds/sync')->everyFifteenMinutes()
 *
 * Each method sets part of an expression, so they compose — frequency first, then days,
 * then time — exactly as Laravel's scheduler does. The result is an ordinary cron
 * expression, checked and run like one written by hand; `->cron()` takes one directly.
 *
 * Nothing here throws. A mistake is recorded and raised by JobConfig — see fail().
 */
final class Job
{
    private const DAYS = [
        'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
        'thursday' => 4, 'friday' => 5, 'saturday' => 6,
    ];

    /** @var array{0: string, 1: string, 2: string, 3: string, 4: string} minute, hour, day of month, month, day of week */
    private array $fields = ['*', '*', '*', '*', '*'];

    private ?string $timezone = null;
    private ?string $description = null;
    private bool|string|null $enabled = null;
    private ?string $problem = null;

    private function __construct(private readonly string $command)
    {
    }

    /**
     * A misspelt method (`->daly()`) would otherwise be a fatal error while the config file
     * loads — on every request, web included. Recorded like any other problem instead.
     *
     * @param array<mixed> $arguments
     */
    public function __call(string $name, array $arguments): self
    {
        return $this->fail("there is no {$name}() method; see the README for the ones there are.");
    }

    /**
     * The same, for a misspelt `Job::command()`.
     *
     * @param array<mixed> $arguments
     */
    public static function __callStatic(string $name, array $arguments): self
    {
        $job = new self(is_string($arguments[0] ?? null) ? $arguments[0] : '?');

        return $job->fail("there is no Job::{$name}(); a job starts with Job::command('<route>').");
    }

    /**
     * What is wrong with the job, if anything. JobConfig refuses a job that has a problem.
     */
    public function getProblem(): ?string
    {
        return $this->problem;
    }

    /**
     * @param string $command a console route with its arguments, e.g. `gc/run --silent`
     */
    public static function command(string $command): self
    {
        return new self($command);
    }

    // Raw -----------------------------------------------------------------------------

    /**
     * A five-field cron expression. Methods chained after it change parts of it.
     */
    public function cron(string $expression): self
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ($fields === false || count($fields) !== 5 || !CronExpression::isValidExpression($expression)) {
            return $this->fail("'{$expression}' is not a five-field cron expression.");
        }
        $this->fields = [$fields[0], $fields[1], $fields[2], $fields[3], $fields[4]];

        return $this;
    }

    // Minutes and hours -----------------------------------------------------------------

    public function everyMinute(): self
    {
        return $this->set(0, '*');
    }

    public function everyNMinutes(int $minutes): self
    {
        $this->range($minutes, 1, 59, 'everyNMinutes()');

        return $this->set(0, $minutes === 1 ? '*' : "*/{$minutes}");
    }

    public function everyTwoMinutes(): self
    {
        return $this->everyNMinutes(2);
    }

    public function everyFiveMinutes(): self
    {
        return $this->everyNMinutes(5);
    }

    public function everyTenMinutes(): self
    {
        return $this->everyNMinutes(10);
    }

    public function everyFifteenMinutes(): self
    {
        return $this->everyNMinutes(15);
    }

    public function everyThirtyMinutes(): self
    {
        return $this->everyNMinutes(30);
    }

    public function hourly(): self
    {
        return $this->set(0, '0');
    }

    public function hourlyAt(int $minute): self
    {
        $this->range($minute, 0, 59, 'hourlyAt()');

        return $this->set(0, (string)$minute);
    }

    public function everyNHours(int $hours, int $minute = 0): self
    {
        $this->range($hours, 1, 23, 'everyNHours()');
        $this->range($minute, 0, 59, 'everyNHours() minute');

        return $this->set(0, (string)$minute)->set(1, $hours === 1 ? '*' : "*/{$hours}");
    }

    public function everyTwoHours(): self
    {
        return $this->everyNHours(2);
    }

    public function everySixHours(): self
    {
        return $this->everyNHours(6);
    }

    // Days --------------------------------------------------------------------------

    public function daily(): self
    {
        return $this->set(0, '0')->set(1, '0');
    }

    /**
     * Once a day at `HH:MM`, 24-hour. The same as `->at()`.
     */
    public function dailyAt(string $time): self
    {
        return $this->at($time);
    }

    /**
     * Sets the time of day and nothing else, so it follows a day method:
     * `->weekdays()->at('08:00')`, `->mondays()->at('09:30')`.
     */
    public function at(string $time): self
    {
        [$hour, $minute] = $this->time($time);

        return $this->set(0, (string)$minute)->set(1, (string)$hour);
    }

    public function twiceDaily(int $first = 1, int $second = 13, int $minute = 0): self
    {
        $this->range($first, 0, 23, 'twiceDaily() first hour');
        $this->range($second, 0, 23, 'twiceDaily() second hour');
        $this->range($minute, 0, 59, 'twiceDaily() minute');

        return $this->set(0, (string)$minute)->set(1, "{$first},{$second}");
    }

    public function weekdays(): self
    {
        return $this->set(4, '1-5');
    }

    public function weekends(): self
    {
        return $this->set(4, '6,0');
    }

    public function mondays(): self
    {
        return $this->days('monday');
    }

    public function tuesdays(): self
    {
        return $this->days('tuesday');
    }

    public function wednesdays(): self
    {
        return $this->days('wednesday');
    }

    public function thursdays(): self
    {
        return $this->days('thursday');
    }

    public function fridays(): self
    {
        return $this->days('friday');
    }

    public function saturdays(): self
    {
        return $this->days('saturday');
    }

    public function sundays(): self
    {
        return $this->days('sunday');
    }

    /**
     * Only on these days: names (`'monday'`) or numbers, 0 = Sunday.
     */
    public function days(int|string ...$days): self
    {
        if ($days === []) {
            return $this->fail('days() needs at least one day.');
        }

        return $this->set(4, implode(',', array_map(fn($day) => (string)$this->day($day), $days)));
    }

    // Weeks, months, years -------------------------------------------------------------

    public function weekly(): self
    {
        return $this->set(0, '0')->set(1, '0')->set(4, '0');
    }

    public function weeklyOn(int|string $day, string $time = '00:00'): self
    {
        return $this->set(4, (string)$this->day($day))->at($time);
    }

    public function monthly(): self
    {
        return $this->set(0, '0')->set(1, '0')->set(2, '1');
    }

    public function monthlyOn(int $day = 1, string $time = '00:00'): self
    {
        $this->range($day, 1, 31, 'monthlyOn() day');

        return $this->set(2, (string)$day)->at($time);
    }

    public function lastDayOfMonth(string $time = '00:00'): self
    {
        return $this->set(2, 'L')->at($time);
    }

    public function quarterly(): self
    {
        return $this->set(0, '0')->set(1, '0')->set(2, '1')->set(3, '1-12/3');
    }

    public function yearly(): self
    {
        return $this->set(0, '0')->set(1, '0')->set(2, '1')->set(3, '1');
    }

    // Everything else a job can say ----------------------------------------------------

    public function timezone(string $timezone): self
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * A bool, or a string Craft reads as one (`'$SCHEDULE_REMINDERS'`).
     */
    public function enabled(bool|string $enabled): self
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getExpression(): string
    {
        return implode(' ', $this->fields);
    }

    /**
     * The job in the array form `config/scheduler.php` also accepts, for JobConfig to check.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'command' => $this->command,
            'cron' => $this->getExpression(),
            'timezone' => $this->timezone,
            'description' => $this->description,
            'enabled' => $this->enabled,
        ], fn($value) => $value !== null);
    }

    // -------------------------------------------------------------------------------

    private function set(int $position, string $value): self
    {
        $this->fields[$position] = $value;

        return $this;
    }

    /**
     * @return array{int, int}
     */
    private function time(string $time): array
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches) || (int)$matches[1] > 23 || (int)$matches[2] > 59) {
            $this->fail("'{$time}' is not a 24-hour time like '03:00' or '17:30'.");
            return [0, 0];
        }

        return [(int)$matches[1], (int)$matches[2]];
    }

    private function day(int|string $day): int
    {
        if (is_string($day)) {
            $key = strtolower($day);
            if (!isset(self::DAYS[$key])) {
                $this->fail("'{$day}' is not a day of the week.");
                return 0;
            }
            return self::DAYS[$key];
        }

        $this->range($day, 0, 6, 'day number (0 = Sunday)');

        return $day;
    }

    private function range(int $value, int $min, int $max, string $what): void
    {
        if ($value < $min || $value > $max) {
            $this->fail("{$what} must be {$min}–{$max}, not {$value}.");
        }
    }

    /**
     * Records the first thing wrong with the job, and never throws.
     *
     * config/scheduler.php is loaded on EVERY request, web included, because it is the
     * plugin's settings file — so an exception here would take the whole site down over a
     * typo in one job. JobConfig raises it instead, when the schedule is built, which only
     * `scheduler/run` and `scheduler/list` do.
     */
    private function fail(string $message): self
    {
        $this->problem ??= "scheduler: job “{$this->command}”: {$message}";

        return $this;
    }
}
