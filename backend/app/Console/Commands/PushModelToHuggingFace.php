<?php

namespace App\Console\Commands;

use App\Jobs\PushReplicateModelToHuggingFace;
use App\Models\AiModel;
use Illuminate\Console\Command;

class PushModelToHuggingFace extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'model:push-to-hf 
                            {model_id : The ID of the AI model to push}
                            {--sync : Run synchronously instead of dispatching to queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Manually push a trained AI model from Replicate to HuggingFace';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $modelId = (int) $this->argument('model_id');
        $sync = $this->option('sync');

        $this->info("Pushing model #{$modelId} to HuggingFace...");

        // Find the AI model
        $aiModel = AiModel::find($modelId);

        if (!$aiModel) {
            $this->error("❌ AI Model with ID {$modelId} not found");
            return 1;
        }

        // Display model information
        $this->line("");
        $this->info("Model Information:");
        $this->line("  ID: {$aiModel->id}");
        $this->line("  Name: {$aiModel->name}");
        $this->line("  Status: {$aiModel->status}");
        $this->line("  Replicate Prediction ID: " . ($aiModel->replicates_prediction_id ?? 'N/A'));
        $this->line("  HuggingFace Model ID: " . ($aiModel->huggingface_model_id ?? 'N/A'));
        $this->line("");

        // Validate required fields
        if (!$aiModel->replicates_prediction_id) {
            $this->error("❌ Missing Replicate prediction ID. Model may not have been trained yet.");
            return 1;
        }

        if (!$aiModel->huggingface_model_id) {
            $this->error("❌ Missing HuggingFace model ID. Model may not have been created yet.");
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

        try {
            if ($sync) {
                $this->info("Running push job synchronously...");
                
                // Create and run the job directly
                $job = new PushReplicateModelToHuggingFace($modelId);
                $job->handle();
                
                $this->info("✅ Model successfully pushed to HuggingFace!");
            } else {
                $this->info("Dispatching push job to queue...");
                
                // Dispatch to queue
                PushReplicateModelToHuggingFace::dispatch($modelId);
                
                $this->info("✅ Push job dispatched to queue. Check logs for progress.");
            }

            // Refresh model to show updated status
            $aiModel->refresh();
            $this->line("");
            $this->info("Updated Model Status: {$aiModel->status}");

            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Error pushing model to HuggingFace:");
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

