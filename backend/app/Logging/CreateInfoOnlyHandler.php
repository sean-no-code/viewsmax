<?php

namespace App\Logging;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class CreateInfoOnlyHandler
{
    /**
     * Create a custom Monolog instance.
     *
     * @param  array  $config
     * @return \Monolog\Logger
     */
    public function __invoke(array $config)
    {
        $logger = new Logger('info_file');
        
        $handler = new InfoOnlyHandler(
            $config['handler_with']['stream'] ?? storage_path('logs/laravel.log'),
            $config['handler_with']['level'] ?? \Monolog\Level::Debug,
            $config['handler_with']['bubble'] ?? true,
            $config['handler_with']['filePermission'] ?? null,
            $config['handler_with']['useLocking'] ?? false
        );
        
        $logger->pushHandler($handler);
        
        return $logger;
    }
}

