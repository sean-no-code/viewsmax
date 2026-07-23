<?php

namespace App\Console\Commands;

use App\Jobs\PushModelToComfyUI as PushModelToComfyUIJob;
use App\Models\AiModel;
use App\Services\ComfyUIService;
use Illuminate\Console\Command;

class PushModelToComfyUI extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:push-to-comfyui 
                            {model_id : The ID of the AI model to push}
                            {--sync : Run synchronously instead of dispatching to queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push a trained AI model from Replicate to ComfyUI server via SCP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $modelId = (int) $this->argument('model_id');
        $sync = $this->option('sync');

        $this->info("Pushing model #{$modelId} to ComfyUI server...");

        // Find the AI model
        $aiModel = AiModel::find($modelId);

        if (!$aiModel) {
            $this->error("❌ AI Model with ID {$modelId} not found");
            return 1;
        }

        // Get ComfyUI service for displaying info
        $comfyUIService = app(ComfyUIService::class);
        $targetFilename = $comfyUIService->getLoraFilename($aiModel);
        $remotePath = config('services.comfyui.remote_lora_path', '/home/ubuntu/ComfyUI/models/loras');
        $sshHost = config('services.comfyui.ssh_host');
        $sshUser = config('services.comfyui.ssh_user', 'ubuntu');

        // Display model information
        $this->line("");
        $this->info("📋 Model Information:");
        $this->line("  ID: {$aiModel->id}");
        $this->line("  Name: {$aiModel->name}");
        $this->line("  Status: {$aiModel->status}");
        $this->line("  Replicate Prediction ID: " . ($aiModel->replicates_prediction_id ?? 'N/A'));
        $this->line("");

        $this->info("🖥️  ComfyUI Server Configuration:");
        $this->line("  Host: {$sshHost}");
        $this->line("  User: {$sshUser}");
        $this->line("  Remote Path: {$remotePath}");
        $this->line("");

        $this->info("📁 Upload Details:");
        $this->line("  Target Filename: <fg=yellow>{$targetFilename}</>");
        $this->line("  Full Remote Path: {$remotePath}/{$targetFilename}");
        $this->line("");

        // Validate required fields
        if (!$aiModel->replicates_prediction_id) {
            $this->error("❌ Missing Replicate prediction ID. Model may not have been trained yet.");
            return 1;
        }

        // Check if model is in a valid state
        if (!in_array($aiModel->status, ['training_completed', 'completed', 'processing'])) {
            $this->warn("⚠️  Model status is '{$aiModel->status}'. Expected: training_completed, completed, or processing.");
            
            if (!$this->confirm('Do you want to proceed anyway?', false)) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        // Check SSH key
        $sshKeyPath = config('services.comfyui.ssh_key_path');
        if (str_starts_with($sshKeyPath, '~')) {
            $sshKeyPath = str_replace('~', $_SERVER['HOME'] ?? getenv('HOME'), $sshKeyPath);
        }

        if (!file_exists($sshKeyPath)) {
            $this->error("❌ SSH key not found at: {$sshKeyPath}");
            $this->line("   Please set COMFYUI_SSH_KEY_PATH in your .env file");
            return 1;
        }

        $this->line("✓ SSH key found at: {$sshKeyPath}");
        $this->line("");

        try {
            if ($sync) {
                $this->info("🚀 Running push job synchronously...");
                $this->line("");
                
                // Create and run the job directly
                $job = new PushModelToComfyUIJob($modelId);
                $job->handle();
                
                // Check if LoRA exists on server
                $this->line("");
                $this->info("🔍 Verifying upload...");
                
                try {
                    $loraExists = $comfyUIService->loraExists($targetFilename);
                    if ($loraExists) {
                        $this->info("✅ LoRA '{$targetFilename}' is available on ComfyUI server!");
                    } else {
                        $this->warn("⚠️  LoRA not found in server list. Server may need restart to detect new files.");
                    }
                } catch (\Exception $e) {
                    $this->warn("⚠️  Could not verify: {$e->getMessage()}");
                }
                
                $this->line("");
                $this->info("✅ Model successfully pushed to ComfyUI!");
            } else {
                $this->info("📤 Dispatching push job to queue...");
                
                // Dispatch to queue
                PushModelToComfyUIJob::dispatch($modelId);
                
                $this->info("✅ Push job dispatched to queue. Check logs for progress.");
            }

            // Refresh model to show updated status
            $aiModel->refresh();
            $this->line("");
            $this->info("Updated Model Status: {$aiModel->status}");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Error pushing model to ComfyUI:");
            $this->error($e->getMessage());
            
            if ($this->option('verbose')) {
                $this->line("");
                $this->line("Stack trace:");
                $this->line($e->getTraceAsString());
            }
            
            return 1;
        }
    }
}
