<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\YouTubeSearchService;
use Illuminate\Console\Command;

class SearchYouTube extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'search:youtube {term} {--user=1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Search YouTube for a term and fetch data';

    /**
     * Execute the console command.
     */
    public function handle(YouTubeSearchService $service)
    {
        $term = $this->argument('term');
        $userId = $this->option('user');

        $user = User::find($userId);
        if (!$user) {
            $this->error("User with ID {$userId} not found.");
            return;
        }

        $this->info("Searching for '{$term}' for user {$user->name} (ID: {$user->id})...");

        try {
            $service->search($term, $user);
            $this->info('Search completed successfully.');
        } catch (\Exception $e) {
            $this->error('Search failed: ' . $e->getMessage());
        }
    }
}
