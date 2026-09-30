<?php

namespace webdna\scheduler;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;
use omnilight\scheduling\ScheduleController as LibraryScheduleController;
use webdna\scheduler\console\controllers\MovedController;
use webdna\scheduler\models\Settings;
use yii\base\InvalidConfigException;

/**
 * Scheduler plugin
 *
 * The schedule is `config/scheduler.php`. The host runs one entry every minute,
 * `craft scheduler/run`, and that works out which jobs are due and runs them.
 *
 * @method static Scheduler getInstance()
 * @method Settings getSettings()
 * @author webdna
 * @copyright webdna
 * @license MIT
 */
class Scheduler extends Plugin
{
    public string $schemaVersion = '1.0.0';

    /**
     * No CP settings, on purpose. The schedule belongs in the repository; a CP screen
     * would put it back in the database, where it is invisible to review and missing on
     * every new environment — the thing this plugin exists to stop.
     */
    public bool $hasCpSettings = false;

    public function init(): void
    {
        parent::init();

        // Console only. The web app gets no controllers at all, so
        // /actions/scheduler/run is a 404 rather than a way to run the schedule over HTTP.
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'webdna\\scheduler\\console\\controllers';
            $this->closeLibraryCommand(Craft::$app);
        }
    }

    /**
     * Makes `schedule/run` fail rather than succeed doing nothing.
     *
     * The library's own bootstrap maps `schedule` to its controller on every console app,
     * with no schedule file, so it runs an empty schedule and exits 0 — the one outcome a
     * host entry left on the old command must never have. Only the library's bare default
     * is replaced; a project that configured `schedule` itself keeps it.
     */
    private function closeLibraryCommand(ConsoleApplication $app): void
    {
        $mapped = $app->controllerMap['schedule'] ?? null;

        if ($mapped === null || $mapped === LibraryScheduleController::class) {
            $app->controllerMap['schedule'] = MovedController::class;
        }
    }

    /**
     * Builds the schedule from `config/scheduler.php`.
     *
     * @param callable(string): void $write where each job's output is replayed once it finishes
     * @throws InvalidConfigException when a job, the timezone or the craft script is invalid
     */
    public function buildSchedule(callable $write): Schedule
    {
        $settings = $this->getSettings();

        $timezone = JobConfig::timezone($settings->timezone, 'timezone');
        $jobs = JobConfig::normalise($settings->jobs);

        $schedule = new Schedule([
            'cliScriptName' => $this->craftScript(),
            'timezone' => $timezone,
            'logFile' => $this->logFile(),
            'singleServer' => $settings->singleServer,
        ]);
        $schedule->startReplay($write);

        foreach ($jobs as $job) {
            if (!$job['enabled']) {
                $schedule->disabled[] = $job;
                continue;
            }

            $event = $schedule->job($job['command'], $job['cron']);

            if ($job['timezone'] !== null) {
                $event->timezone($job['timezone']);
            }
            if ($job['description'] !== null) {
                $event->description($job['description']);
            }
        }

        if ($settings->configure !== null) {
            if (!is_callable($settings->configure)) {
                throw new InvalidConfigException('scheduler: `configure` must be a callable that takes the Schedule.');
            }
            call_user_func($settings->configure, $schedule);
        }

        return $schedule;
    }

    /**
     * Where job output is appended.
     */
    public function logFile(): string
    {
        $logFile = $this->getSettings()->logFile;

        if ($logFile === null || $logFile === '') {
            return Craft::$app->getPath()->getLogPath() . '/scheduler.log';
        }

        return Craft::getAlias(App::parseEnv($logFile));
    }

    /**
     * The absolute path of the `craft` script each job is spawned through.
     *
     * The library's default is the bare string `yii` — a vanilla Yii entry script no
     * Craft project has — so left alone it spawns nothing and reports nothing. It must
     * also be absolute, because cron does not guarantee a working directory.
     *
     * @throws InvalidConfigException
     */
    public function craftScript(): string
    {
        $script = Craft::getAlias('@root') . DIRECTORY_SEPARATOR . 'craft';

        if (!is_file($script)) {
            throw new InvalidConfigException("scheduler: no craft script at {$script}, so no job could run.");
        }

        return $script;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
