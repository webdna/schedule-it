<?php

namespace webdna\scheduler;

use Craft;
use omnilight\scheduling\Event as BaseEvent;
use Symfony\Component\Process\Process;
use yii\base\Application;

/**
 * A scheduled command that remembers how it ended.
 *
 * The library throws the exit code away, so its runner reports success whatever a job
 * did. This keeps the code and the time taken, and always runs in the foreground from the
 * project root — the library would background any job without an after-callback, and
 * every job here has at least one.
 */
class Event extends BaseEvent
{
    public ?int $exitCode = null;

    public ?float $duration = null;

    public function run(Application $app)
    {
        $this->trigger(self::EVENT_BEFORE_RUN);

        $start = microtime(true);
        $process = Process::fromShellCommandline(trim($this->buildCommand(), '& '), Craft::getAlias('@root'), null, null, null);
        $process->run();
        $this->exitCode = $process->getExitCode();
        $this->duration = microtime(true) - $start;

        $this->callAfterCallbacks($app);
        $this->trigger(self::EVENT_AFTER_RUN);
    }

    public function getTimezone(): ?string
    {
        $timezone = $this->_timezone;

        return $timezone instanceof \DateTimeZone ? $timezone->getName() : $timezone;
    }

    public function failed(): bool
    {
        return $this->exitCode !== null && $this->exitCode !== 0;
    }
}
