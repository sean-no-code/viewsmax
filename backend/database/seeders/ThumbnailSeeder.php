<?php

namespace Database\Seeders;

use App\Jobs\GenerateThumbnailsJob;
use App\Models\Prompt;
use App\Models\PromptType;
use App\Models\Thumbnail;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class ThumbnailSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info("ThumbnailSeeder started");

        // Check if environment is production
        if (app()->environment('production')) {
            $message = "ThumbnailSeeder cannot be run in production environment.";
            Log::warning($message);
            $this->command->error($message);
            $this->command->info("To prevent accidental data loss, seeding is disabled in production.");
            return;
        }

        // Drop all thumbnails from the table
        Log::info("Dropping all thumbnails from table");
        DB::table('thumbnails')->truncate();
        $this->command->info("Truncated thumbnails table");

        // Find the first admin user
        $adminUser = User::where('email', 'admin@testaccount.com')->first();
        
        if (!$adminUser) {
            Log::warning("Admin user not found, skipping thumbnail seeding");
            return;
        }

        Log::info("Found admin user for thumbnail seeding", [
            'user_id' => $adminUser->id,
            'email' => $adminUser->email
        ]);

        // Get prompt IDs for different types
        $negativePrompt = Prompt::whereHas('promptType', function($query) {
            $query->where('name', 'negative');
        })->where('is_active', true)->first();

        $generalPrompt = Prompt::whereHas('promptType', function($query) {
            $query->where('name', 'general');
        })->where('is_active', true)->first();

        // Get all style prompts
        $stylePrompts = Prompt::whereHas('promptType', function($query) {
            $query->where('name', 'style');
        })->where('is_active', true)->orderBy('version')->get();

        Log::info("Found prompts for thumbnail seeding", [
            'negative_prompt_id' => $negativePrompt?->id,
            'general_prompt_id' => $generalPrompt?->id,
            'style_prompts_count' => $stylePrompts->count()
        ]);

        // Get test images from resources/images/tests/thumbnails/
        $testImagesDir = resource_path('images/tests/thumbnails');
        $defaultGrandparentImagePath = $testImagesDir . '/default_grandparent_record_image.jpg';
        $defaultParentImagePath = $testImagesDir . '/default_parent_record_image.jpg';
        $defaultChildImagePath = $testImagesDir . '/default_child_record_image.jpg';

        // Check if test images exist
        if (!File::exists($defaultGrandparentImagePath)) {
            Log::warning("Default grandparent image not found", ['path' => $defaultGrandparentImagePath]);
            $this->command->warn("Default grandparent image not found at: {$defaultGrandparentImagePath}");
        }

        if (!File::exists($defaultParentImagePath)) {
            Log::warning("Default parent image not found", ['path' => $defaultParentImagePath]);
            $this->command->warn("Default parent image not found at: {$defaultParentImagePath}");
        }

        if (!File::exists($defaultChildImagePath)) {
            Log::warning("Default child image not found", ['path' => $defaultChildImagePath]);
            $this->command->warn("Default child image not found at: {$defaultChildImagePath}");
        }

        // Create grandparent thumbnail
        Log::info("Creating grandparent thumbnail");
        
        $grandparentThumbnail = Thumbnail::create([
            'description' => 'Test grandparent thumbnail',
            'prompt' => 'Test grandparent prompt',
            'user_id' => $adminUser->id,
            'status' => 'completed',
            'negative_prompt_id' => $negativePrompt?->id,
            'style_prompt_id' => $stylePrompts->first()?->id,
            'general_prompt_id' => $generalPrompt?->id,
            'processed_at' => now()
        ]);
        
        // Copy default_grandparent_record_image to storage for grandparent
        $grandparentFileLocation = null;
        if (File::exists($defaultGrandparentImagePath)) {
            $grandparentFileLocation = $this->copyTestImageToStorage(
                new \SplFileInfo($defaultGrandparentImagePath),
                $adminUser->id,
                $grandparentThumbnail->id
            );
            $grandparentThumbnail->update(['file_location' => $grandparentFileLocation]);
        }
        
        Log::info("Created grandparent thumbnail", [
            'thumbnail_id' => $grandparentThumbnail->id,
            'description' => $grandparentThumbnail->description,
            'file_location' => $grandparentThumbnail->file_location
        ]);

        // Create parent thumbnail
        Log::info("Creating parent thumbnail");
        
        $parentThumbnail = Thumbnail::create([
            'description' => 'Test parent thumbnail',
            'prompt' => 'Test parent prompt',
            'user_id' => $adminUser->id,
            'status' => 'completed',
            'parent_id' => $grandparentThumbnail->id,
            'negative_prompt_id' => $negativePrompt?->id,
            'style_prompt_id' => $stylePrompts->first()?->id,
            'general_prompt_id' => $generalPrompt?->id,
            'processed_at' => now()
        ]);
        
        // Copy default_parent_record_image to storage for parent
        $parentFileLocation = null;
        if (File::exists($defaultParentImagePath)) {
            $parentFileLocation = $this->copyTestImageToStorage(
                new \SplFileInfo($defaultParentImagePath),
                $adminUser->id,
                $parentThumbnail->id
            );
            $parentThumbnail->update(['file_location' => $parentFileLocation]);
        }
        
        Log::info("Created parent thumbnail", [
            'thumbnail_id' => $parentThumbnail->id,
            'parent_id' => $grandparentThumbnail->id,
            'description' => $parentThumbnail->description,
            'file_location' => $parentThumbnail->file_location
        ]);
        
        // Create child thumbnail
        Log::info("Creating child thumbnail");
        
        $childThumbnail = Thumbnail::create([
            'description' => 'Test child thumbnail',
            'prompt' => 'Test child prompt',
            'user_id' => $adminUser->id,
            'status' => 'completed',
            'parent_id' => $parentThumbnail->id,
            'negative_prompt_id' => $negativePrompt?->id,
            'style_prompt_id' => $stylePrompts->first()?->id,
            'general_prompt_id' => $generalPrompt?->id,
            'processed_at' => now()
        ]);
        
        // Copy default_child_record_image to storage for child
        $childFileLocation = null;
        if (File::exists($defaultChildImagePath)) {
            $childFileLocation = $this->copyTestImageToStorage(
                new \SplFileInfo($defaultChildImagePath),
                $adminUser->id,
                $childThumbnail->id
            );
            $childThumbnail->update(['file_location' => $childFileLocation]);
        }
        
        Log::info("Created child thumbnail", [
            'thumbnail_id' => $childThumbnail->id,
            'parent_id' => $parentThumbnail->id,
            'description' => $childThumbnail->description,
            'file_location' => $childThumbnail->file_location
        ]);

        Log::info("ThumbnailSeeder completed successfully", [
            'grandparent_thumbnail_id' => $grandparentThumbnail->id,
            'parent_thumbnail_id' => $parentThumbnail->id,
            'child_thumbnail_id' => $childThumbnail->id,
            'admin_user_id' => $adminUser->id
        ]);

        // Display results
        $this->command->info("Created grandparent thumbnail (ID: {$grandparentThumbnail->id}) for admin user {$adminUser->email}");
        $this->command->info("Created parent thumbnail (ID: {$parentThumbnail->id}) for admin user {$adminUser->email}");
        $this->command->info("Created child thumbnail (ID: {$childThumbnail->id}) for admin user {$adminUser->email}");
        $this->command->line("");
        $this->command->line("  🔗 Parent/Child Relationships:");
        $this->command->line("    - Grandparent Thumbnail ID {$grandparentThumbnail->id}: {$grandparentThumbnail->description}");
        $this->command->line("      └─ Parent Thumbnail ID {$parentThumbnail->id}: {$parentThumbnail->description}");
        $this->command->line("         └─ Child Thumbnail ID {$childThumbnail->id}: {$childThumbnail->description}");
        $this->command->line("");
    }

    /**
     * Copy test image from resources to storage
     *
     * @param \SplFileInfo $testImage
     * @param int $userId
     * @param int|null $thumbnailId
     * @param string|null $prefix
     * @return string
     */
    private function copyTestImageToStorage(\SplFileInfo $testImage, int $userId, ?int $thumbnailId = null, ?string $prefix = null): string
    {
        $extension = $testImage->getExtension();
        $timestamp = now()->format('Y-m-d_H-i-s');
        
        // Determine folder path
        if ($thumbnailId) {
            $folderPath = "thumbnails/{$userId}/{$thumbnailId}";
            $fileName = "{$thumbnailId}_1_{$timestamp}.{$extension}";
        } else {
            // Use prefix for temporary name, will be updated after thumbnail creation
            $tempId = $prefix ?? 'temp';
            $folderPath = "thumbnails/{$userId}/{$tempId}";
            $fileName = "{$tempId}_1_{$timestamp}.{$extension}";
        }
        
        // Ensure directory exists
        if (!Storage::disk('public')->exists($folderPath)) {
            Storage::disk('public')->makeDirectory($folderPath);
        }
        
        $filePath = "{$folderPath}/{$fileName}";
        
        // Copy the file
        $imageContent = File::get($testImage->getPathname());
        Storage::disk('public')->put($filePath, $imageContent);
        
        Log::info("Copied test image to storage", [
            'source' => $testImage->getPathname(),
            'destination' => $filePath,
            'user_id' => $userId,
            'thumbnail_id' => $thumbnailId
        ]);
        
        return $filePath;
    }
}