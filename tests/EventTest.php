<?php

namespace webdna\scheduleit\tests;

use PHPUnit\Framework\TestCase;
use webdna\scheduleit\Event;
use webdna\scheduleit\Schedule;
use yii\base\Application;
use yii\caching\ArrayCache;
use yii\mutex\Mutex;

class EventTest extends TestCase
{
    /**
     * Locks held by any SharedMutex: two instances stand for two runner processes.
     *
     * @var array<string, true>
     */
    public static array $held = [];

    private string $dir;

    protected function setUp(): void
    {
        self::$held = [];
        $this->dir = sys_get_temp_dir() . '/schedule-it-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    /**
     * Issue #1: the library took every due job's lock while working out what was due.
     */
    public function testWorkingOutWhatIsDueTakesNoLock(): void
    {
        $event = $this->event('true')->withoutOverlapping()->onOneServer();

        $this->assertTrue($event->isDue($this->app()));
        $this->assertSame([], self::$held);
    }

    public function testAJobWhoseLockIsHeldIsSkippedAndNotRun(): void
    {
        $event = $this->event('touch ran');
        (new SharedMutex())->acquire('schedule-it/' . $event->getId());

        $event->run($this->app());

        $this->assertSame(Event::SKIPPED_RUNNING, $event->skipped);
        $this->assertNull($event->exitCode);
        $this->assertFileDoesNotExist($this->dir . '/ran');
    }

    public function testTheLockIsHeldOnlyWhileTheJobRuns(): void
    {
        $event = $this->event('exit 3');
        $name = 'schedule-it/' . $event->getId();
        $heldDuring = null;
        $event->then(function() use ($name, &$heldDuring): void {
            $heldDuring = isset(self::$held[$name]);
        });

        $event->run($this->app());

        $this->assertTrue($heldDuring);
        $this->assertSame([], self::$held, 'released after a failure too');
        $this->assertSame(3, $event->exitCode);
        $this->assertTrue($event->failed());
    }

    public function testAJobIsStartedOnceForAMinuteHoweverManyRunnersReachIt(): void
    {
        $cache = new ArrayCache();
        $minute = new \DateTimeImmutable('2026-10-20 09:15:20', new \DateTimeZone('UTC'));

        $first = $this->event('true', $cache, $minute);
        $first->run($this->app());
        $this->assertSame(0, $first->exitCode);

        // A second runner in the same minute, with its own mutex: the lock is free again,
        // but the job has already been started for 09:15.
        $second = $this->event('true', $cache, $minute->modify('+20 seconds'), new SharedMutex());
        $second->run($this->app());
        $this->assertSame(Event::SKIPPED_STARTED, $second->skipped);

        $next = $this->event('true', $cache, $minute->modify('+1 minute'));
        $next->run($this->app());
        $this->assertNull($next->skipped);

        // A runner still working through 09:15's jobs finds the 09:16 run already done.
        $late = $this->event('true', $cache, $minute);
        $late->run($this->app());
        $this->assertSame(Event::SKIPPED_STARTED, $late->skipped);
    }

    public function testTheIdIgnoresPathsAndFollowsTheExpressionAndCommand(): void
    {
        $a = $this->event('true');
        $a->route = 'gc/run';
        $b = $this->event('/other/php /other/craft gc/run');
        $b->route = 'gc/run';
        $c = $this->event('true')->cron('0 3 * * *');
        $c->route = 'gc/run';

        $this->assertSame($a->getId(), $b->getId());
        $this->assertNotSame($a->getId(), $c->getId());
    }

    public function testAShellJobLogsEveryPartOfItsLine(): void
    {
        $schedule = (new \ReflectionClass(Schedule::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Schedule::class, '_mutex'))->setValue($schedule, new SharedMutex());
        $schedule->logFile = $this->dir . '/log';
        $schedule->cliScriptName = $this->dir . '/craft';

        $echoed = '';
        $schedule->echoOutputTo(function(string $chunk) use (&$echoed): void {
            $echoed .= $chunk;
        });

        $event = $schedule->execJob('echo one && echo two >&2; exit 4', '* * * * *');
        $event->cwd = $this->dir;
        $event->run($this->app());

        $this->assertSame("one\ntwo\n", file_get_contents($this->dir . '/log'));
        $this->assertSame("one\ntwo\n", $echoed);
        $this->assertSame(4, $event->exitCode);
        $this->assertSame('echo one && echo two >&2; exit 4', $event->getSummaryForDisplay());
        $this->assertSame('echo one && echo two >&2; exit 4', $event->getCommandForDisplay());
    }

    /**
     * Two runners on one machine share the log. Each must echo only its own job's output,
     * not whatever the other appended to the file meanwhile.
     */
    public function testEachRunnerEchoesOnlyItsOwnJobsOutput(): void
    {
        $log = $this->dir . '/log';
        $other = $this->event('echo from-the-other-runner');
        $other->logFile = $log;
        $echoed = '';
        $mine = $this->event('echo mine');
        $mine->logFile = $log;
        $mine->write = function(string $chunk) use (&$echoed): void {
            $echoed .= $chunk;
        };

        $other->run($this->app());
        $mine->run($this->app());

        $this->assertSame("mine\n", $echoed);
        $this->assertSame("from-the-other-runner\nmine\n", file_get_contents($log));
    }

    private function event(string $command, ?ArrayCache $cache = null, ?\DateTimeImmutable $dueAt = null, ?Mutex $mutex = null): Event
    {
        $event = new Event($mutex ?? new SharedMutex(), $command);
        $event->cron('* * * * *');
        $event->cwd = $this->dir;
        $event->cache = $cache;
        $event->dueAt = $dueAt;

        return $event;
    }

    private function app(): Application
    {
        return $this->createMock(Application::class);
    }
}

/**
 * A mutex every instance shares, like a database lock seen from two processes.
 */
class SharedMutex extends Mutex
{
    public $autoRelease = false;

    protected function acquireLock($name, $timeout = 0)
    {
        if (isset(EventTest::$held[$name])) {
            return false;
        }
        EventTest::$held[$name] = true;

        return true;
    }

    protected function releaseLock($name)
    {
        unset(EventTest::$held[$name]);

        return true;
    }
}
