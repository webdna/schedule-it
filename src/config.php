<?php
/**
 * Scheduler config.php
 *
 * Copy this file to your project's `config/` folder as `scheduler.php`. That copy IS the
 * schedule: the host runs one entry every minute —
 *
 *     * * * * *  php craft scheduler/run
 *
 * — and it works out which of these jobs are due and runs them.
 *
 * NEVER add `queue/run` or `queue/listen`. The host (or Craft itself) already runs the
 * queue; a second consumer runs jobs twice, and `queue/listen` never exits, so it would
 * block every job after it.
 */

use craft\helpers\App;
use webdna\scheduler\Job;

return [
    'jobs' => [
        // In words (times are read in UTC):
        // Job::command('gc/run')->dailyAt('03:00'),
        // Job::command('reports/send')->weekdays()->at('08:30'),

        // Or the command, arguments included => a cron expression.
        // 'gc/run' => '0 3 * * *',

        // Or an array, to set a description, a timezone, or switch it per environment.
        // 'my-module/reminders/send' => [
        //     'cron' => '0 8 * * *',
        //     'description' => 'Membership reminders — sends real email',
        //     'enabled' => App::env('CRAFT_ENVIRONMENT') === 'production',
        // ],
    ],

    // 'timezone' => 'UTC',
    // 'logFile' => '@storage/logs/scheduler.log',

    // Only on a host that can never run two app servers; see the README.
    // 'singleServer' => false,
];
