<?php

namespace App\Console\Commands;

use App\Services\HuggingFaceService;
use Illuminate\Console\Command;

class ListHuggingFaceModels extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hf:list-models 
                            {--namespace= : Override the namespace (defaults to config)}
                            {--limit=20 : Number of models to display}
                            {--json : Output as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List Hugging Face models in the configured namespace';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $namespace = $this->option('namespace') ?: config('services.huggingface.namespace', 'viewsmax');
        $limit = (int) $this->option('limit');
        $jsonOutput = $this->option('json');
        
        $this->info("Listing Hugging Face models...");
        $this->line("Namespace: {$namespace}");
        $this->line("Limit: {$limit}");
        $this->line("");
        
        try {
            $huggingFaceService = new HuggingFaceService();
            
            // Override namespace if provided
            if ($this->option('namespace')) {
                $huggingFaceService = new HuggingFaceService();
                // We need to modify the service to use custom namespace
                // For now, we'll use reflection to set the namespace
                $reflection = new \ReflectionClass($huggingFaceService);
                $namespaceProperty = $reflection->getProperty('namespace');
                $namespaceProperty->setAccessible(true);
                $namespaceProperty->setValue($huggingFaceService, $namespace);
            }
            
            $models = $huggingFaceService->listModels();
            
            if ($models === null) {
                $this->error("❌ Failed to fetch models from Hugging Face");
                return 1;
            }
            
            if (empty($models)) {
                $this->warn("No models found in namespace '{$namespace}'");
                return 0;
            }
            
            // Limit the results
            $models = array_slice($models, 0, $limit);
            
            if ($jsonOutput) {
                $this->line(json_encode($models, JSON_PRETTY_PRINT));
                return 0;
            }
            
            // Display models in table format
            $this->info("Found " . count($models) . " model(s):");
            $this->line("");
            
            $headers = ['ID', 'Name', 'Private', 'Type', 'Downloads', 'Created'];
            $rows = [];
            
            foreach ($models as $model) {
                $rows[] = [
                    $model['id'] ?? 'N/A',
                    $model['name'] ?? 'N/A',
                    $model['private'] ? 'Yes' : 'No',
                    $model['type'] ?? 'N/A',
                    number_format($model['downloads'] ?? 0),
                    isset($model['created_at']) ? date('Y-m-d H:i:s', strtotime($model['created_at'])) : 'N/A'
                ];
            }
            
            $this->table($headers, $rows);
            
            // Show additional info
            $this->line("");
            $this->info("Additional Information:");
            foreach ($models as $model) {
                $url = $model['url'] ?? 'No URL';
                $this->line("• {$model['id']}: {$url}");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ Error listing models:");
            $this->error($e->getMessage());
            
            if ($this->option('verbose')) {
                $this->line("");
                $this->line("Stack trace:");
                $this->line($e->getTraceAsString());
            }
            
            return 1;
        }
        
        return 0;
    }
}