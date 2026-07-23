<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class QueueManagerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:manage 
                            {action : start|stop|restart|status}
                            {--workers=4 : Number of workers to start}
                            {--timeout=300 : Worker timeout in seconds}
                            {--memory=512 : Memory limit in MB}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manage Laravel queue workers for concurrent processing';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $action = $this->argument('action');
        $workers = (int) $this->option('workers');
        $timeout = (int) $this->option('timeout');
        $memory = (int) $this->option('memory');

        switch ($action) {
            case 'start':
                $this->startWorkers($workers, $timeout, $memory);
                break;
            case 'stop':
                $this->stopWorkers();
                break;
            case 'restart':
                $this->stopWorkers();
                sleep(2);
                $this->startWorkers($workers, $timeout, $memory);
                break;
            case 'status':
                $this->showStatus();
                break;
            default:
                $this->error('Invalid action. Use: start, stop, restart, or status');
                return 1;
        }

        return 0;
    }

    /**
     * Start multiple queue workers
     */
    private function startWorkers(int $workers, int $timeout, int $memory): void
    {
        $this->info("Starting {$workers} queue workers...");

        for ($i = 1; $i <= $workers; $i++) {
            $command = "php artisan queue:work database --sleep=3 --tries=3 --timeout={$timeout} --memory={$memory} --verbose";
            
            $process = Process::start($command);
            
            $this->info("Worker {$i} started (PID: {$process->id()})");
            
            // Store process ID for management
            file_put_contents(storage_path("app/worker_{$i}.pid"), $process->id());
        }

        $this->info("All {$workers} workers started successfully!");
        $this->line("Use 'php artisan queue:manage status' to check worker status");
    }

    /**
     * Stop all queue workers
     */
    private function stopWorkers(): void
    {
        $this->info('Stopping all queue workers...');

        // Find and stop workers by PID files
        for ($i = 1; $i <= 10; $i++) { // Check up to 10 workers
            $pidFile = storage_path("app/worker_{$i}.pid");
            
            if (file_exists($pidFile)) {
                $pid = trim(file_get_contents($pidFile));
                
                if ($pid && posix_kill($pid, 0)) { // Check if process exists
                    posix_kill($pid, SIGTERM);
                    $this->info("Stopped worker {$i} (PID: {$pid})");
                }
                
                unlink($pidFile);
            }
        }

        // Also kill any remaining queue:work processes
        $result = Process::run('pkill -f "queue:work"');
        
        $this->info('All workers stopped!');
    }

    /**
     * Show worker status
     */
    private function showStatus(): void
    {
        $this->info('Queue Worker Status:');
        $this->line('');

        // Check for running workers
        $result = Process::run('pgrep -f "queue:work"');
        $pids = array_filter(explode("\n", trim($result->output())));

        if (empty($pids)) {
            $this->warn('No queue workers are currently running');
            return;
        }

        $this->info('Running workers:');
        foreach ($pids as $pid) {
            $this->line("  - PID: {$pid}");
        }

        // Check queue status
        $this->line('');
        $this->info('Queue Status:');
        
        $pendingJobs = \DB::table('jobs')->count();
        $failedJobs = \DB::table('failed_jobs')->count();
        
        $this->line("  - Pending jobs: {$pendingJobs}");
        $this->line("  - Failed jobs: {$failedJobs}");
    }
}
