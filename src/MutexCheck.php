<?php

namespace webdna\scheduler;

use craft\mutex\Mutex as CraftMutex;
use craft\mutex\NullMutex;
use yii\mutex\FileMutex;
use yii\mutex\Mutex;

/**
 * Whether the app's mutex can stop two servers running the same job at once.
 *
 * The library's own guard, `onOneServer()`, only refuses a FileMutex — and it tests the
 * outer component. In Craft that is always `craft\mutex\Mutex`, a wrapper around the
 * real driver, so the guard never fires however the driver is configured. This checks the
 * driver instead.
 */
final class MutexCheck
{
    /**
     * The mutex that actually holds the lock.
     */
    public static function driver(?Mutex $mutex): ?Mutex
    {
        if ($mutex instanceof CraftMutex) {
            return $mutex->mutex instanceof Mutex ? $mutex->mutex : null;
        }

        return $mutex;
    }

    /**
     * A lock another server can see. A file lock lives on one machine's disk; Craft's
     * NullMutex (used before Craft is installed) locks nothing at all.
     */
    public static function isShared(?Mutex $driver): bool
    {
        return $driver !== null
            && !$driver instanceof FileMutex
            && !$driver instanceof NullMutex;
    }
}
