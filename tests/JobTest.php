<?php

namespace webdna\scheduler\tests;

use Cron\CronExpression;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\scheduler\Job;
use webdna\scheduler\JobConfig;
use yii\base\InvalidConfigException;

class JobTest extends TestCase
{
    /**
     * @return array<string, array{Job, string}>
     */
    public static function expressions(): array
    {
        $job = static fn() => Job::command('a/b');

        return [
            'nothing set runs every minute' => [$job(), '* * * * *'],
            'everyMinute' => [$job()->everyMinute(), '* * * * *'],
            'everyFiveMinutes' => [$job()->everyFiveMinutes(), '*/5 * * * *'],
            'everyFifteenMinutes' => [$job()->everyFifteenMinutes(), '*/15 * * * *'],
            'everyNMinutes(1) is every minute' => [$job()->everyNMinutes(1), '* * * * *'],
            'hourly' => [$job()->hourly(), '0 * * * *'],
            'hourlyAt' => [$job()->hourlyAt(15), '15 * * * *'],
            'everyTwoHours' => [$job()->everyTwoHours(), '0 */2 * * *'],
            'everyNHours at a minute' => [$job()->everyNHours(6, 30), '30 */6 * * *'],
            'daily' => [$job()->daily(), '0 0 * * *'],
            'dailyAt' => [$job()->dailyAt('03:00'), '0 3 * * *'],
            'dailyAt a single-digit hour' => [$job()->dailyAt('7:05'), '5 7 * * *'],
            'twiceDaily' => [$job()->twiceDaily(), '0 1,13 * * *'],
            'twiceDaily at chosen hours' => [$job()->twiceDaily(9, 17, 30), '30 9,17 * * *'],
            'weekdays at' => [$job()->weekdays()->at('08:30'), '30 8 * * 1-5'],
            'at then weekdays is the same' => [$job()->at('08:30')->weekdays(), '30 8 * * 1-5'],
            'weekends hourly' => [$job()->weekends()->hourly(), '0 * * * 6,0'],
            'mondays at' => [$job()->mondays()->at('09:00'), '0 9 * * 1'],
            'sundays' => [$job()->sundays()->daily(), '0 0 * * 0'],
            'days by name and number' => [$job()->days('monday', 3, 'Friday')->at('12:00'), '0 12 * * 1,3,5'],
            'weekly' => [$job()->weekly(), '0 0 * * 0'],
            'weeklyOn' => [$job()->weeklyOn('tuesday', '04:15'), '15 4 * * 2'],
            'monthly' => [$job()->monthly(), '0 0 1 * *'],
            'monthlyOn' => [$job()->monthlyOn(15, '06:00'), '0 6 15 * *'],
            'lastDayOfMonth' => [$job()->lastDayOfMonth('23:00'), '0 23 L * *'],
            'quarterly' => [$job()->quarterly(), '0 0 1 1-12/3 *'],
            'yearly' => [$job()->yearly(), '0 0 1 1 *'],
            'cron as given' => [$job()->cron('30 2 * * *'), '30 2 * * *'],
            'cron then weekdays' => [$job()->cron('30 2 * * *')->weekdays(), '30 2 * * 1-5'],
        ];
    }

    #[DataProvider('expressions')]
    public function testEachMethodBuildsTheExpectedExpression(Job $job, string $expected): void
    {
        $this->assertSame($expected, $job->getExpression());
        $this->assertTrue(CronExpression::isValidExpression($job->getExpression()), 'the cron library must accept it');
    }

    public function testToArrayCarriesOnlyWhatWasSet(): void
    {
        $this->assertSame(['command' => 'gc/run', 'cron' => '0 3 * * *'], Job::command('gc/run')->dailyAt('03:00')->toArray());

        $this->assertSame([
            'command' => 'mail/send',
            'cron' => '0 8 * * *',
            'timezone' => 'Europe/London',
            'description' => 'Reminders',
            'enabled' => '$SCHEDULE_REMINDERS',
        ], Job::command('mail/send')->dailyAt('08:00')->timezone('Europe/London')->description('Reminders')->enabled('$SCHEDULE_REMINDERS')->toArray());
    }

    /**
     * @return array<string, array{callable(): Job, string}>
     */
    public static function invalid(): array
    {
        return [
            'hour past 23' => [fn() => Job::command('a/b')->dailyAt('24:00'), "'24:00' is not a 24-hour time"],
            'minute past 59' => [fn() => Job::command('a/b')->at('10:60'), "'10:60' is not a 24-hour time"],
            'a time in words' => [fn() => Job::command('a/b')->at('3am'), "'3am' is not a 24-hour time"],
            'unknown day' => [fn() => Job::command('a/b')->days('mon'), "'mon' is not a day of the week"],
            'day number 7' => [fn() => Job::command('a/b')->days(7), 'must be 0–6'],
            'no days' => [fn() => Job::command('a/b')->days(), 'needs at least one day'],
            'every 0 minutes' => [fn() => Job::command('a/b')->everyNMinutes(0), 'must be 1–59'],
            'every 24 hours' => [fn() => Job::command('a/b')->everyNHours(24), 'must be 1–23'],
            'monthlyOn day 32' => [fn() => Job::command('a/b')->monthlyOn(32), 'must be 1–31'],
            'bad cron' => [fn() => Job::command('a/b')->cron('0 3 * *'), 'not a five-field cron expression'],
            // @phpstan-ignore method.notFound
            'misspelt method' => [fn() => Job::command('a/b')->daly(), 'there is no daly() method'],
            // @phpstan-ignore staticMethod.notFound
            'misspelt command()' => [fn() => Job::comand('a/b')->daily(), 'there is no Job::comand()'],
            'the first mistake is the one reported' => [fn() => Job::command('a/b')->at('25:00')->days('mon'), "'25:00'"],
        ];
    }

    /**
     * config/scheduler.php is loaded on every request, web included, so building a job must
     * never throw — a typo would take the site down. The mistake surfaces from JobConfig,
     * which only runs when the schedule is built.
     */
    #[DataProvider('invalid')]
    public function testAMistakeNeverThrowsWhileTheConfigLoadsAndIsRefusedWhenTheScheduleIsBuilt(callable $build, string $message): void
    {
        $job = $build();
        $this->assertStringContainsString($message, (string)$job->getProblem());

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);
        JobConfig::normalise([$job]);
    }
}
