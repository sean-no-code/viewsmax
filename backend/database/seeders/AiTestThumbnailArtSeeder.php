<?php

namespace Database\Seeders;

/**
 * AiTestThumbnailArtSeeder
 * 
 * NOTE: This seeder creates a thumbnail record that will be processed via AI.
 * The thumbnail will be generated in an art style.
 * This thumbnail is created with status 'pending' and will be processed
 * by the GenerateThumbnailsJob when the queue worker runs.
 */

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class AiTestThumbnailArtSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info("AiTestThumbnailArtSeeder started");

        // Check if environment is production
        if (app()->environment('production')) {
            $message = "AiTestThumbnailArtSeeder cannot be run in production environment.";
            Log::warning($message);
            $this->command->error($message);
            $this->command->info("To prevent accidental data loss, seeding is disabled in production.");
            return;
        }

        // Find the admin user
        $adminUser = User::where('email', 'admin@testaccount.com')->first();
        
        if (!$adminUser) {
            Log::warning("Admin user not found, skipping thumbnail seeding");
            $this->command->warn("Admin user not found. Please run UserSeeder first.");
            return;
        }

        // Create thumbnail with art style
        $description = "A samurai walking along a blood-red path leading to the bold text \"SELF MASTERY\" at the top in a traditional ink-painting style";

        $thumbnail = Thumbnail::create([
            'description' => $description,
            'prompt' => $description,
            'user_id' => $adminUser->id,
            'status' => 'pending', // Will be processed by GenerateThumbnailsJob
        ]);

        Log::info("Created thumbnail with art style", [
            'thumbnail_id' => $thumbnail->id,
            'description' => $description,
        ]);

        // Dispatch the job to generate the thumbnail
        GenerateThumbnailsJob::dispatch($thumbnail->id);

        $this->command->info("Created thumbnail #{$thumbnail->id}: " . substr($description, 0, 60) . "...");

        Log::info("AiTestThumbnailArtSeeder completed successfully", [
            'thumbnail_id' => $thumbnail->id,
            'admin_user_id' => $adminUser->id
        ]);

        // Display results
        $this->command->line("");
        $this->command->info("Created thumbnail with art style record");
        $this->command->info("Thumbnail is set to 'pending' status and will be processed by the queue");
        $this->command->line("  - Thumbnail ID {$thumbnail->id}: " . substr($thumbnail->description, 0, 70) . "...");
        $this->command->line("");
    }
}

