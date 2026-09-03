<?php

use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Monolog\Processor\PsrLogMessageProcessor;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that is utilized to write
    | messages to your logs. The value provided here should match one of
    | the channels present in the list of "channels" configured below.
    |
    */

    'default' => env('LOG_CHANNEL', 'split'),

    /*
    |--------------------------------------------------------------------------
    | Deprecations Log Channel
    |--------------------------------------------------------------------------
    |
    | This option controls the log channel that should be used to log warnings
    | regarding deprecated PHP and library features. This allows you to get
    | your application ready for upcoming major versions of dependencies.
    |
    */

    'deprecations' => [
        'channel' => env('LOG_DEPRECATIONS_CHANNEL', 'null'),
        'trace' => env('LOG_DEPRECATIONS_TRACE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Laravel
    | utilizes the Monolog PHP logging library, which includes a variety
    | of powerful log handlers and formatters that you're free to use.
    |
    | Available drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog", "custom", "stack"
    |
    */

    'channels' => [

        'stack' => [
            'driver' => 'stack',
            'channels' => explode(',', (string) env('LOG_STACK', 'single,error_file')),
            'ignore_exceptions' => false,
            'permission' => 0777, // Set permissions to 777
        ],

        'split' => [
            'driver' => 'stack',
            'channels' => ['info_file', 'error_file', 'error_slack'], // Split logs: info to laravel.log, errors to error.log
            'ignore_exceptions' => false,
            'permission' => 0777,
        ],

        // Dedicated channel for outbound-email diagnostics. Unlike the default
        // "split" channel (which sends info -> laravel.log and errors ->
        // error.log via InfoOnlyHandler), this writes EVERYTHING mail-related —
        // attempts, successes and failures — to a single storage/logs/mail.log
        // at debug level, so email delivery can be traced in one place. Errors
        // still bubble to Slack via error_slack.
        'mail' => [
            'driver' => 'stack',
            'channels' => ['mail_file', 'error_slack'],
            'ignore_exceptions' => false,
            'permission' => 0777,
        ],

        'mail_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/mail.log'),
            'level' => 'debug',
            'replace_placeholders' => true,
            'permission' => 0777,
        ],

        'info_file' => [
            'driver' => 'custom',
            'via' => \App\Logging\CreateInfoOnlyHandler::class,
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
            'handler_with' => [
                'stream' => storage_path('logs/laravel.log'),
                'level' => \Monolog\Logger::DEBUG,
                'bubble' => true,
            ],
        ],

        'error_file' => [
            'driver' => 'single',
            'path' => storage_path('logs/error.log'),
            'level' => env('LOG_ERROR_LEVEL', 'error'), // Use separate env var for error log level
            'replace_placeholders' => true,
            'permission' => 0777, // Set permissions to 777
        ],

        'error_slack' => [
            // Fall back to a no-op handler in development OR whenever no webhook URL
            // is configured. Without this, Monolog's Slack handler hits curl with an
            // empty URL and throws ("Malformed input to a URL function"), turning any
            // error-level log into a crash. Dev/test can simply leave the URL unset.
            //
            // Note: the no-op fallback must be the "monolog" driver with a NullHandler,
            // NOT the string "null" — "null" is a channel name, not a registered driver,
            // and using it here throws "Driver [null] is not supported".
            'driver' => (env('APP_ENV') === 'development' || empty(env('LOG_SLACK_WEBHOOK_URL'))) ? 'monolog' : 'slack',
            'handler' => NullHandler::class,
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'TubeMaster Error Bot'),
            'emoji' => env('LOG_SLACK_EMOJI', ':warning:'),
            'level' => env('LOG_SLACK_LEVEL', 'error'),
            'replace_placeholders' => true,
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'info'),
            'replace_placeholders' => true,
            'permission' => 0777, // Set permissions to 777
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => env('LOG_DAILY_DAYS', 14),
            'replace_placeholders' => true,
            'permission' => 0777, // Set permissions to 777
        ],

        'slack' => [
            'driver' => env('APP_ENV') === 'development' ? 'monolog' : 'slack',
            'handler' => NullHandler::class,
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => env('LOG_SLACK_USERNAME', 'TubeMaster Error Bot'),
            'emoji' => env('LOG_SLACK_EMOJI', ':warning:'),
            'level' => env('LOG_SLACK_LEVEL', 'error'),
            'replace_placeholders' => true,
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => env('LOG_PAPERTRAIL_HANDLER', SyslogUdpHandler::class),
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
                'connectionString' => 'tls://' . env('PAPERTRAIL_URL') . ':' . env('PAPERTRAIL_PORT'),
            ],
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'debug'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'processors' => [PsrLogMessageProcessor::class],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => env('LOG_LEVEL', 'debug'),
            'facility' => env('LOG_SYSLOG_FACILITY', LOG_USER),
            'replace_placeholders' => true,
        ],

        'errorlog' => [
            'driver' => 'errorlog',
            'level' => env('LOG_LEVEL', 'debug'),
            'replace_placeholders' => true,
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'path' => storage_path('logs/laravel.log'),
        ],

    ],

];
