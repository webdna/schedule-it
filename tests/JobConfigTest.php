<?php

namespace webdna\scheduler\tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use webdna\scheduler\Job;
use webdna\scheduler\JobConfig;
use yii\base\InvalidConfigException;

class JobConfigTest extends TestCase
{
    public function testAStringIsTheCronExpression(): void
    {
        $this->assertSame([[
            'command' => 'gc/run',
            'cron' => '0 3 * * *',
            'timezone' => null,
            'description' => null,
            'enabled' => true,
        ]], JobConfig::normalise(['gc/run' => '0 3 * * *']));
    }

    public function testAnArrayCarriesTheOptionalKeys(): void
    {
        $job = JobConfig::normalise(['reminders/send --verbose' => [
            'cron' => '0 8 * * *',
            'timezone' => 'Europe/London',
            'description' => 'Reminders',
            'enabled' => false,
        ]])[0];

        $this->assertSame('reminders/send --verbose', $job['command']);
        $this->assertSame('Europe/London', $job['timezone']);
        $this->assertSame('Reminders', $job['description']);
        $this->assertFalse($job['enabled']);
    }

    public function testAListEntryNamesItsCommandSoOneCommandCanRunOnTwoSchedules(): void
    {
        $jobs = JobConfig::normalise([
            ['command' => 'sync/run', 'cron' => '0 6 * * 1-5'],
            ['command' => 'sync/run', 'cron' => '0 12 * * 6,0'],
        ]);

        $this->assertSame(['sync/run', 'sync/run'], array_column($jobs, 'command'));
    }

    public function testAJobBuilderSitsBesideCronStrings(): void
    {
        $jobs = JobConfig::normalise([
            'gc/run' => '0 3 * * *',
            Job::command('reports/send')->weekdays()->at('08:30')->timezone('Europe/London')->enabled(false),
        ]);

        $this->assertSame(['command' => 'reports/send', 'cron' => '30 8 * * 1-5', 'timezone' => 'Europe/London', 'description' => null, 'enabled' => false], $jobs[1]);
    }

    public function testAnEnabledStringIsReadAsABoolean(): void
    {
        $this->assertFalse(JobConfig::normalise(['a/b' => ['cron' => '* * * * *', 'enabled' => 'false']])[0]['enabled']);
        $this->assertTrue(JobConfig::normalise(['a/b' => ['cron' => '* * * * *', 'enabled' => '1']])[0]['enabled']);
    }

    /**
     * @return array<string, array{array<int|string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'bad expression' => [['gc/run' => '0 3 * *'], 'valid cron expression'],
            'no cron' => [['gc/run' => ['description' => 'x']], 'valid cron expression'],
            // A typo must fail loudly: the alternative is a job that never runs.
            'misspelt key' => [['gc/run' => ['cron' => '0 3 * * *', 'enable' => false]], 'unknown key enable'],
            'no command' => [[['cron' => '0 3 * * *']], 'has no command'],
            'two commands' => [['gc/run' => ['command' => 'other', 'cron' => '0 3 * * *']], 'different `command`'],
            'bad timezone' => [['gc/run' => ['cron' => '0 3 * * *', 'timezone' => 'BST']], 'not a timezone identifier'],
            'bad enabled' => [['gc/run' => ['cron' => '0 3 * * *', 'enabled' => 'sometimes']], '`enabled` must be'],
            'not a string or array' => [['gc/run' => 3], 'must be a cron expression, an array or a Job'],
            'job under another command' => [['gc/run' => Job::command('other')->daily()], 'different `command`'],
        ];
    }

    /**
     * @param array<int|string, mixed> $jobs
     */
    #[DataProvider('invalid')]
    public function testInvalidConfigIsRefused(array $jobs, string $message): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage($message);

        JobConfig::normalise($jobs);
    }
}
