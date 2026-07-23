<?php

namespace App\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\LogRecord;
use Monolog\Level;

class InfoOnlyHandler extends StreamHandler
{
    /**
     * {@inheritDoc}
     */
    public function handle(LogRecord $record): bool
    {
        // Only handle logs that are NOT error level or above
        // Error level is 400, so we only handle levels below 400 (ERROR, CRITICAL, ALERT, EMERGENCY)
        // Accept: DEBUG (100), INFO (200), NOTICE (250), WARNING (300)
        // Reject: ERROR (400), CRITICAL (500), ALERT (550), EMERGENCY (600)
        if ($record->level->value >= Level::Error->value) {
            return false; // Don't process errors or above
        }

        return parent::handle($record);
    }
    
    /**
     * Create a new handler instance.
     *
     * @param  string|resource  $stream
     * @param  int  $level
     * @param  bool  $bubble
     * @param  int|null  $filePermission
     * @param  bool  $useLocking
     */
    public function __construct($stream, $level = Level::Debug, bool $bubble = true, ?int $filePermission = null, bool $useLocking = false)
    {
        parent::__construct($stream, $level, $bubble, $filePermission, $useLocking);
    }
}

