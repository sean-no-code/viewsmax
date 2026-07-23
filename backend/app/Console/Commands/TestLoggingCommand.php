<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TestLoggingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'test:logging {--level=info : Log level (info, error, warning, debug)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Test logging functionality by writing test log entries';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $level = $this->option('level');
        $timestamp = now()->format('Y-m-d H:i:s');
        
        $this->info("Testing logging with level: {$level}");
        
        switch ($level) {
            case 'info':
                Log::info('Test info log entry', [
                    'timestamp' => $timestamp,
                    'command' => 'test:logging',
                    'level' => 'info'
                ]);
                $this->info('Info log entry written successfully');
                break;
                
            case 'error':
                Log::error('Test error log entry', [
                    'timestamp' => $timestamp,
                    'command' => 'test:logging',
                    'level' => 'error',
                    'error_code' => 'TEST_ERROR_001'
                ]);
                $this->error('Error log entry written successfully');
                break;
                
            case 'warning':
                Log::warning('Test warning log entry', [
                    'timestamp' => $timestamp,
                    'command' => 'test:logging',
                    'level' => 'warning'
                ]);
                $this->warn('Warning log entry written successfully');
                break;
                
            case 'debug':
                Log::debug('Test debug log entry', [
                    'timestamp' => $timestamp,
                    'command' => 'test:logging',
                    'level' => 'debug'
                ]);
                $this->info('Debug log entry written successfully');
                break;
                
            default:
                $this->error("Invalid log level: {$level}. Valid levels are: info, error, warning, debug");
                return 1;
        }
        
        $this->info("Log entry written to: " . storage_path('logs/laravel.log'));
        
        return 0;
    }
}

