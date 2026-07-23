<?php

namespace Database\Seeders;

/**
 * AiTestThumbnailWithAiModelSeeder
 * 
 * NOTE: This seeder creates a thumbnail record that will be processed via AI.
 * The thumbnail will be generated using the seeded AI model (Demo Model).
 * This thumbnail is created with status 'pending' and will be processed
 * by the GenerateThumbnailsJob when the queue worker runs.
 */

use App\Jobs\GenerateThumbnailsJob;
use App\Models\AiModel;
use App\Models\Prompt;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class AiTestThumbnailWithAiModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info("AiTestThumbnailWithAiModelSeeder started");

        // Check if environment is production
        if (app()->environment('production')) {
            $message = "AiTestThumbnailWithAiModelSeeder cannot be run in production environment.";
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

        // Find the seeded AI model (Demo Model)
        $aiModel = AiModel::where('name', 'Demo Model')
            ->where('user_id', $adminUser->id)
            ->first();
        
        if (!$aiModel) {
            Log::warning("AI model 'Demo Model' not found, skipping thumbnail seeding");
            $this->command->warn("AI model 'Demo Model' not found. Please run AiModelSeeder first.");
            return;
        }

        Log::info("Found AI model for thumbnail seeding", [
            'ai_model_id' => $aiModel->id,
            'ai_model_name' => $aiModel->name,
            'user_id' => $adminUser->id
        ]);

        // Get all active style prompts
        $stylePrompts = Prompt::whereHas('promptType', function($query) {
            $query->where('name', 'style');
        })->where('is_active', true)->orderBy('version')->get();

        if ($stylePrompts->isEmpty()) {
            Log::warning("No style prompts found, skipping thumbnail seeding");
            $this->command->warn("No style prompts found. Please run PromptSeeder first.");
            return;
        }

        Log::info("Found style prompts for thumbnail seeding", [
            'style_prompts_count' => $stylePrompts->count(),
            'style_prompt_ids' => $stylePrompts->pluck('id')->toArray()
        ]);

        // Create thumbnail with AI model
        $description = "A man swimming underwater, looking terrified at the camera while a shark looms menacingly in the background.";

        $createdThumbnails = [];

        // Create one thumbnail for each style prompt
        foreach ($stylePrompts as $stylePrompt) {
            $thumbnail = Thumbnail::create([
                'description' => $description,
                'prompt' => $description,
                'user_id' => $adminUser->id,
                'ai_model_id' => $aiModel->id,
                'style_prompt_id' => $stylePrompt->id,
                'status' => 'pending', // Will be processed by GenerateThumbnailsJob
            ]);

            Log::info("Created thumbnail with AI model and style prompt", [
                'thumbnail_id' => $thumbnail->id,
                'description' => $description,
                'ai_model_id' => $aiModel->id,
                'style_prompt_id' => $stylePrompt->id,
                'style_prompt_title' => $stylePrompt->title,
            ]);

            // Dispatch the job to generate the thumbnail
            GenerateThumbnailsJob::dispatch($thumbnail->id);

            $createdThumbnails[] = $thumbnail;

            $this->command->info("Created thumbnail #{$thumbnail->id} with style prompt #{$stylePrompt->id}: " . substr($description, 0, 50) . "...");
        }

        Log::info("AiTestThumbnailWithAiModelSeeder completed successfully", [
            'thumbnails_created' => count($createdThumbnails),
            'ai_model_id' => $aiModel->id,
            'admin_user_id' => $adminUser->id,
            'style_prompts_count' => $stylePrompts->count()
        ]);

        // Display results
        $this->command->line("");
        $this->command->info("Created " . count($createdThumbnails) . " thumbnail(s) with AI model record");
        $this->command->info("Using AI model: {$aiModel->name} (ID: {$aiModel->id})");
        $this->command->info("Thumbnails are set to 'pending' status and will be processed by the queue");
        $this->command->line("");
        foreach ($createdThumbnails as $thumbnail) {
            $stylePrompt = $stylePrompts->firstWhere('id', $thumbnail->style_prompt_id);
            $stylePromptTitle = $stylePrompt ? $stylePrompt->title : 'ID ' . $thumbnail->style_prompt_id;
            $this->command->line("  - Thumbnail ID {$thumbnail->id}: " . substr($thumbnail->description, 0, 50) . "... (Style Prompt: {$stylePromptTitle})");
        }
        $this->command->line("");
    }
}

