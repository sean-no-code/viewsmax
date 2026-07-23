<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AiModelType;
use App\Models\Ethnicity;
use App\Models\FileCategory;
use App\Models\FileUpload;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AiModelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info("AiModelSeeder started");

        // Check if environment is production
        if (app()->environment('production')) {
            $message = "AiModelSeeder cannot be run in production environment.";
            Log::warning($message);
            $this->command->error($message);
            $this->command->info("To prevent accidental data loss, seeding is disabled in production.");
            return;
        }

        // Find the admin user
        $adminUser = User::where('email', 'admin@testaccount.com')->first();
        
        if (!$adminUser) {
            Log::warning("Admin user not found, skipping AI model seeding");
            $this->command->warn("Admin user not found. Please run UserSeeder first.");
            return;
        }

        // Get ethnicity "White"
        $ethnicity = Ethnicity::where('name', 'White')->first();
        
        if (!$ethnicity) {
            Log::warning("Ethnicity 'White' not found, skipping AI model seeding");
            $this->command->warn("Ethnicity 'White' not found. Please run EthnicitySeeder first.");
            return;
        }

        // Get AI model type "male"
        $aiModelType = AiModelType::where('name', 'male')->first();
        
        if (!$aiModelType) {
            Log::warning("AI model type 'male' not found, skipping AI model seeding");
            $this->command->warn("AI model type 'male' not found. Please run AiModelTypeSeeder first.");
            return;
        }

        // Get file category "AI Model Thumbnail"
        $thumbnailCategory = FileCategory::where('name', 'AI Model Thumbnail')->first();
        
        if (!$thumbnailCategory) {
            Log::warning("File category 'AI Model Thumbnail' not found, skipping AI model seeding");
            $this->command->warn("File category 'AI Model Thumbnail' not found. Please run FileCategorySeeder first.");
            return;
        }

        // Check if thumbnail image exists
        $thumbnailSourcePath = resource_path('images/thumbnail_1.jpg');
        
        if (!File::exists($thumbnailSourcePath)) {
            Log::warning("Thumbnail image not found", ['path' => $thumbnailSourcePath]);
            $this->command->warn("Thumbnail image not found at: {$thumbnailSourcePath}");
            return;
        }

        // Check if AI model already exists with this name
        $existingModel = AiModel::where('name', 'Demo Model')
            ->where('user_id', $adminUser->id)
            ->first();

        if ($existingModel) {
            Log::info("AI model 'Demo Model' already exists, skipping creation", [
                'ai_model_id' => $existingModel->id
            ]);
            $this->command->info("AI model 'Demo Model' already exists (ID: {$existingModel->id})");
            return;
        }

        // Create the AI model
        $aiModel = AiModel::create([
            'name' => 'Demo Model',
            'user_id' => $adminUser->id,
            'status' => 'completed',
            'huggingface_model_id' => 'viewsmax/ai-model-1-5-1762250624',
            'huggingface_model_url' => 'https://huggingface.co/viewsmax/ai-model-1-5-1762250624',
            'replicate_model_name' => 'viewsmax/ai-model-1-5-1762250624',
            'replicate_model_url' => 'https://replicate.com/viewsmax/ai-model-1-5-1762250624',
            'bald' => true,
            'age' => 39,
            'ethnicity_id' => $ethnicity->id,
            'ai_model_type_id' => $aiModelType->id,
        ]);

        Log::info("AI model created", [
            'ai_model_id' => $aiModel->id,
            'name' => $aiModel->name,
            'user_id' => $aiModel->user_id
        ]);

        // Copy thumbnail image to storage (only if not already there)
        $thumbnailFileName = 'thumbnail_' . $aiModel->id . '.jpg';
        $thumbnailStoragePath = 'ai-models/' . $adminUser->id . '/' . $thumbnailFileName;
        
        // Ensure directory exists
        $thumbnailDir = 'ai-models/' . $adminUser->id;
        if (!Storage::disk('public')->exists($thumbnailDir)) {
            Storage::disk('public')->makeDirectory($thumbnailDir);
        }

        // Only copy the file if it doesn't already exist in storage
        if (!Storage::disk('public')->exists($thumbnailStoragePath)) {
            $thumbnailContent = File::get($thumbnailSourcePath);
            Storage::disk('public')->put($thumbnailStoragePath, $thumbnailContent);

            Log::info("Thumbnail image copied to storage", [
                'source_path' => $thumbnailSourcePath,
                'storage_path' => $thumbnailStoragePath,
                'full_path' => Storage::disk('public')->path($thumbnailStoragePath)
            ]);
        } else {
            Log::info("Thumbnail image already exists in storage, skipping copy", [
                'storage_path' => $thumbnailStoragePath,
                'full_path' => Storage::disk('public')->path($thumbnailStoragePath)
            ]);
        }

        // Create FileUpload record for thumbnail
        $thumbnailFileUpload = FileUpload::create([
            'name' => $thumbnailFileName,
            'original_name' => 'thumbnail_1.jpg',
            'location' => $thumbnailStoragePath,
            'mime_type' => 'image/jpeg',
            'file_size' => File::size($thumbnailSourcePath),
            'file_category_id' => $thumbnailCategory->id,
            'user_id' => $adminUser->id,
            'ai_model_id' => $aiModel->id,
        ]);

        Log::info("Thumbnail FileUpload created", [
            'file_upload_id' => $thumbnailFileUpload->id,
            'ai_model_id' => $aiModel->id
        ]);

        // Attach file upload to the AI model (for backward compatibility with pivot table)
        $aiModel->fileUploads()->attach($thumbnailFileUpload->id);

        Log::info("AiModelSeeder completed successfully", [
            'ai_model_id' => $aiModel->id,
            'file_upload_id' => $thumbnailFileUpload->id,
            'admin_user_id' => $adminUser->id
        ]);

        // Display results
        $this->command->info("Created AI model 'Demo Model' (ID: {$aiModel->id}) for admin user {$adminUser->email}");
        $this->command->info("  - Status: {$aiModel->status}");
        $this->command->info("  - HuggingFace Model ID: {$aiModel->huggingface_model_id}");
        $this->command->info("  - HuggingFace Model URL: {$aiModel->huggingface_model_url}");
        $this->command->info("  - Replicate Model Name: {$aiModel->replicate_model_name}");
        $this->command->info("  - Replicate Model URL: {$aiModel->replicate_model_url}");
        $this->command->info("  - Bald: " . ($aiModel->bald ? 'Yes' : 'No'));
        $this->command->info("  - Age: {$aiModel->age}");
        $this->command->info("  - Ethnicity: {$ethnicity->name}");
        $this->command->info("  - Type: {$aiModelType->name}");
        $this->command->info("  - Thumbnail FileUpload ID: {$thumbnailFileUpload->id}");
        $this->command->line("");
    }
}
