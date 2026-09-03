<?php

namespace App\Console\Commands;

use App\Services\HuggingFaceService;
use Illuminate\Console\Command;

class CheckHuggingFaceModelAccess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hf:check-model-access 
                            {model_name : The name of the Hugging Face model to check (e.g., ai-model-1-1-1760610765)}
                            {--namespace= : Override the namespace (defaults to config)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a Hugging Face model is accessible using the HuggingFaceService';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $modelName = $this->argument('model_name');
        $namespace = $this->option('namespace') ?: config('services.huggingface.namespace', 'iclicksee');
        
        $this->info("Checking Hugging Face model access...");
        $this->line("Model: {$namespace}/{$modelName}");
        $this->line("Namespace: {$namespace}");
        
        try {
            $huggingFaceService = new HuggingFaceService();
            
            // Generate the full model ID
            $modelId = $namespace . '/' . $modelName;
            
            $this->line("Full Model ID: {$modelId}");
            $this->line("");
            
            // Check if model exists
            $this->info("Checking if model exists...");
            $exists = $huggingFaceService->modelExists($modelName);
            
            if ($exists) {
                $this->info("✅ Model exists on Hugging Face");
                
                // Get model details
                $this->info("Fetching model details...");
                $model = $huggingFaceService->getModel($modelName);
                
                if ($model) {
                    $this->line("Model Details:");
                    $this->line("  - ID: " . ($model['id'] ?? 'N/A'));
                    $this->line("  - URL: " . ($model['url'] ?? 'N/A'));
                    $this->line("  - Private: " . ($model['private'] ?? 'N/A'));
                    $this->line("  - Type: " . ($model['type'] ?? 'N/A'));
                }
                
                // Verify model access
                $this->info("Verifying model access...");
                $accessible = $huggingFaceService->verifyModelAccess($modelName);
                
                if ($accessible) {
                    $this->info("✅ Model is accessible");
                    $this->line("The model can be used for training with Replicate");
                } else {
                    $this->error("❌ Model is not accessible");
                    $this->line("The model may be private or there may be access issues");
                }
                
            } else {
                $this->error("❌ Model does not exist on Hugging Face");
                $this->line("The model '{$modelId}' was not found");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error checking model access:");
            $this->error($e->getMessage());
            
            if ($this->option('verbose')) {
                $this->line("");
                $this->line("Stack trace:");
                $this->line($e->getTraceAsString());
            }
        }
        
        return 0;
    }
}