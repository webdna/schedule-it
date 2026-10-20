<?php

namespace webdna\scheduleit;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\helpers\App;
use omnilight\scheduling\ScheduleController as LibraryScheduleController;
use webdna\scheduleit\console\controllers\MovedController;
use webdna\scheduleit\models\Settings;
use yii\base\InvalidConfigException;

/**
 * Schedule-it plugin
 *
 * The schedule is `config/schedule-it.php`. The host runs one entry every minute,
 * `craft schedule-it/run`, and that works out which jobs are due and runs them.
 *
 * @method static ScheduleIt getInstance()
 * @method Settings getSettings()
 * @author webdna
 * @copyright webdna
 * @license MIT
 */
class ScheduleIt extends Plugin
{
    public string $schemaVersion = '1.0.0';

    /**
     * No CP settings, on purpose. The schedule belongs in the repository; a CP screen
     * would put it back in the database, where it is invisible to review and missing on
     * every new environment — the thing this plugin exists to stop.
     */
    public bool $hasCpSettings = false;

    /**
     * The handle this plugin had until 2.0.0. It was renamed because the Plugin Store
     * already has a `scheduler` (Verbb's), and Craft's update check matches plugins by
     * handle, so every install was offered that plugin's updates.
     */
    public const RENAMED_FROM = 'scheduler';

    public function init(): void
    {
        parent::init();

        // Console only. The web app gets no controllers at all, so
        // /actions/schedule-it/run is a 404 rather than a way to run the schedule over HTTP.
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'webdna\\scheduleit\\console\\controllers';
            $this->closeLibraryCommand(Craft::$app);
            $this->closeRenamedCommand(Craft::$app);
        }
    }

    /**
     * Makes `scheduler/run` — this plugin's command before it was renamed — say where it went.
     *
     * Left unmapped, a host entry still on it fails as an unknown command, which is loud but
     * says nothing useful. Never while the old `scheduler` plugin is still installed: a
     * controller map entry outranks a module, so it would take that plugin's command away.
     */
    private function closeRenamedCommand(ConsoleApplication $app): void
    {
        if (
            !isset($app->controllerMap[self::RENAMED_FROM])
            && !$app->hasModule(self::RENAMED_FROM)
            && !$app->getPlugins()->isPluginInstalled(self::RENAMED_FROM)
        ) {
            $app->controllerMap[self::RENAMED_FROM] = MovedController::class;
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
     * Builds the schedule from `config/schedule-it.php`.
     *
     * @param callable(string): void $write where each job's output is echoed as it is written
     * @throws InvalidConfigException when a job, the timezone or the craft script is invalid
     */
    public function buildSchedule(callable $write): Schedule
    {
        $this->refuseStrandedConfig();

        $settings = $this->getSettings();

        $timezone = JobConfig::timezone($settings->timezone, 'timezone');
        $jobs = JobConfig::normalise($settings->jobs);

        $schedule = new Schedule([
            'cliScriptName' => $this->craftScript(),
            'timezone' => $timezone,
            'logFile' => $this->logFile(),
            'singleServer' => $settings->singleServer,
            'cache' => Craft::$app->getCache(),
        ]);
        $schedule->echoOutputTo($write);

        foreach ($jobs as $job) {
            if (!$job['enabled']) {
                $schedule->disabled[] = $job;
                continue;
            }

            $event = $job['shell']
                ? $schedule->execJob($job['command'], $job['cron'])
                : $schedule->job($job['command'], $job['cron']);
            $event->background($job['background']);

            if ($job['timezone'] !== null) {
                $event->timezone($job['timezone']);
            }
            if ($job['description'] !== null) {
                $event->description($job['description']);
            }
        }

        if ($settings->configure !== null) {
            if (!is_callable($settings->configure)) {
                throw new InvalidConfigException('schedule-it: `configure` must be a callable that takes the Schedule.');
            }
            call_user_func($settings->configure, $schedule);
        }

        return $schedule;
    }

    /**
     * Refuses a schedule left in the file the plugin read before it was renamed.
     *
     * Craft reads a plugin's settings from `config/<handle>.php`, so after the rename
     * `config/scheduler.php` is read by nothing. Without this, every job in it would
     * silently stop and the runner would report "no commands ready" every minute.
     *
     * @throws InvalidConfigException
     */
    private function refuseStrandedConfig(): void
    {
        $configPath = Craft::$app->getPath()->getConfigPath();
        $old = $configPath . DIRECTORY_SEPARATOR . self::RENAMED_FROM . '.php';
        $new = $configPath . DIRECTORY_SEPARATOR . $this->handle . '.php';

        if (is_file($old) && !is_file($new)) {
            throw new InvalidConfigException(
                "schedule-it: the schedule is still in config/scheduler.php, which nothing reads since the plugin was renamed. Rename it to config/schedule-it.php "
                . "and change `use webdna\\scheduler\\Job;` to `use webdna\\scheduleit\\Job;`.",
            );
        }
    }

    /**
     * Where job output is appended.
     */
    public function logFile(): string
    {
        $logFile = $this->getSettings()->logFile;

        if ($logFile === null || $logFile === '') {
            return Craft::$app->getPath()->getLogPath() . '/schedule-it.log';
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
            throw new InvalidConfigException("schedule-it: no craft script at {$script}, so no job could run.");
        }

        return $script;
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }
}
