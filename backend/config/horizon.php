<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Horizon Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where Horizon will be accessible from. If this
    | setting is null, Horizon will reside under the same domain as the
    | application. Otherwise, this value will serve as the subdomain.
    |
    */

    'domain' => null,

    /*
    |--------------------------------------------------------------------------
    | Horizon Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Horizon will be accessible from. Feel free
    | to change this path to anything you like. Note that the URI will not
    | affect the paths of its internal API that aren't exposed to users.
    |
    */

    'path' => 'horizon',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Connection
    |--------------------------------------------------------------------------
    |
    | This is the name of the Redis connection where Horizon will store the
    | meta information required for it to function. It includes the list
    | of supervisors, failed jobs, job metrics, and other information.
    |
    */

    'use' => 'default',

    /*
    |--------------------------------------------------------------------------
    | Horizon Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be used when storing all Horizon data in Redis. You
    | may modify the prefix when you are running multiple installations
    | of Horizon on the same server so that they don't have problems.
    |
    */

    'prefix' => env('HORIZON_PREFIX', 'horizon:'),

    /*
    |--------------------------------------------------------------------------
    | Horizon Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will get attached onto each Horizon route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware. Or, you can simply stick with this list.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Queue Wait Time Thresholds
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure when the LongWaitDetected event
    | will be fired. Every connection / queue combination may have its
    | own, unique threshold (in seconds) before this event is dispatched.
    |
    */

    'waits' => [
        'redis:default' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Trimming Times
    |--------------------------------------------------------------------------
    |
    | Here you can configure for how long (in minutes) you desire Horizon to
    | persist the recent and failed jobs. Typically, you want the recent
    | jobs to be persisted for a few hours while the failed jobs may be
    | persisted for several days.
    |
    */

    'trim' => [
        'recent' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fast Termination
    |--------------------------------------------------------------------------
    |
    | When this option is enabled, Horizon's "terminate" command will not
    | wait on all of the workers within the current environment to finish
    | their current job before terminating the process. This option can
    | be useful when you want to scale your workers down quickly.
    |
    */

    'fast_termination' => false,

    /*
    |--------------------------------------------------------------------------
    | Memory Limit (MB)
    |--------------------------------------------------------------------------
    |
    | This value describes the maximum amount of memory (MB) that Horizon
    | may consume before it is terminated and restarted. You should set
    | this value according to the resources available to your server.
    |
    */

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Queue Worker Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure the queue workers and their process pools.
    | Each worker configuration includes the queue and the number of workers
    | that should process jobs on the given queue. You may also configure
    | the number of seconds each worker should wait before timing out.
    |
    */

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'balance' => 'simple',
                'processes' => 4,
                'tries' => 3,
                'timeout' => 300,
                'memory' => 512,
                'sleep' => 3,
                'max_time' => 3600,
                'max_jobs' => 1000,
                'force' => false,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'database',
                'queue' => ['default'],
                'balance' => 'simple',
                'processes' => 4,
                'tries' => 3,
                'timeout' => 300,
                'memory' => 512,
                'sleep' => 3,
                'max_time' => 3600,
                'max_jobs' => 1000,
                'force' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dead Letter Queue
    |--------------------------------------------------------------------------
    |
    | This option allows you to configure a "dead letter queue" where any
    | job that has failed a maximum number of times will be placed. This
    | allows you to inspect the job and manually retry it if needed.
    |
    */

    'dead_letter_queue' => null,

    /*
    |--------------------------------------------------------------------------
    | Silenced Jobs
    |--------------------------------------------------------------------------
    |
    | Here you may configure the jobs that should be silenced. Silenced jobs
    | will not be logged by Horizon, allowing you to reduce the noise in
    | your logs. You may use wildcards to silence multiple jobs at once.
    |
    */

    'silenced' => [
        // 'App\Jobs\SomeJob',
    ],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Here you may configure the metrics that should be collected by Horizon.
    | By default, Horizon will collect metrics for all jobs. You may disable
    | metrics collection for specific jobs or all jobs if you wish.
    |
    */

    'metrics' => [
        'enabled' => true,
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

];
