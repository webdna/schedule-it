<?php

namespace webdna\scheduler\tests;

use craft\mutex\Mutex as CraftMutex;
use craft\mutex\NullMutex;
use PHPUnit\Framework\TestCase;
use webdna\scheduler\MutexCheck;
use yii\mutex\FileMutex;
use yii\mutex\Mutex;

class MutexCheckTest extends TestCase
{
    public function testTheDriverInsideCraftsWrapperIsTheOneJudged(): void
    {
        // Built without its constructor: init() needs a running Craft app, and all that
        // matters here is which object sits in $mutex.
        $wrapper = (new \ReflectionClass(CraftMutex::class))->newInstanceWithoutConstructor();
        $wrapper->mutex = $file = $this->fileMutex();

        $this->assertSame($file, MutexCheck::driver($wrapper));
        $this->assertFalse(MutexCheck::isShared(MutexCheck::driver($wrapper)));
    }

    public function testAFileOrNullMutexIsNotShared(): void
    {
        $this->assertFalse(MutexCheck::isShared($this->fileMutex()));
        $this->assertFalse(MutexCheck::isShared((new \ReflectionClass(NullMutex::class))->newInstanceWithoutConstructor()));
        $this->assertFalse(MutexCheck::isShared(null));
    }

    public function testAnyOtherDriverIsShared(): void
    {
        $database = new class() extends Mutex {
            protected function acquireLock($name, $timeout = 0)
            {
                return true;
            }

            protected function releaseLock($name)
            {
                return true;
            }
        };

        $this->assertTrue(MutexCheck::isShared($database));
    }

    private function fileMutex(): FileMutex
    {
        return (new \ReflectionClass(FileMutex::class))->newInstanceWithoutConstructor();
    }
}
