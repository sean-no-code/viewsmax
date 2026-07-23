<?php

namespace App\Console\Commands;

use App\Services\ComfyUIService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TestComfyUIConnection extends Command
{
    protected $signature = 'comfyui:test 
                            {--prompt-id= : Test history for a specific prompt ID}';

    protected $description = 'Test ComfyUI server connection and endpoints';

    public function handle()
    {
        $serverUrl = config('services.comfyui.server_url');
        $enabled = config('services.comfyui.enabled');

        $this->info("🔧 ComfyUI Configuration Test");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        
        $this->line("  Server URL: <fg=cyan>{$serverUrl}</>");
        $this->line("  Enabled: " . ($enabled ? '<fg=green>Yes</>' : '<fg=red>No</>'));
        $this->line("");

        if (!$enabled) {
            $this->error("ComfyUI is disabled in configuration");
            return 1;
        }

        // Test basic connectivity
        $this->info("📡 Testing Server Connectivity...");
        $this->line("");

        try {
            // Test /queue endpoint
            $queueUrl = rtrim($serverUrl, '/') . '/queue';
            $this->line("  Testing: {$queueUrl}");
            
            $response = Http::timeout(10)->get($queueUrl);
            
            if ($response->successful()) {
                $queue = $response->json();
                $pending = count($queue['queue_pending'] ?? []);
                $running = count($queue['queue_running'] ?? []);
                
                $this->line("  <fg=green>✓</> Queue endpoint OK");
                $this->line("    Pending: {$pending}, Running: {$running}");
            } else {
                $this->line("  <fg=red>✗</> Queue endpoint failed: {$response->status()}");
            }
            $this->line("");

            // Test /system_stats endpoint
            $statsUrl = rtrim($serverUrl, '/') . '/system_stats';
            $this->line("  Testing: {$statsUrl}");
            
            $response = Http::timeout(10)->get($statsUrl);
            
            if ($response->successful()) {
                $stats = $response->json();
                $this->line("  <fg=green>✓</> System stats endpoint OK");
                if (isset($stats['system']['python_version'])) {
                    $this->line("    Python: {$stats['system']['python_version']}");
                }
            } else {
                $this->line("  <fg=yellow>⚠</> System stats endpoint: {$response->status()}");
            }
            $this->line("");

            // Test LoRA loader info
            $loraUrl = rtrim($serverUrl, '/') . '/object_info/LoraLoader';
            $this->line("  Testing: {$loraUrl}");
            
            $response = Http::timeout(10)->get($loraUrl);
            
            if ($response->successful()) {
                $data = $response->json();
                $loras = $data['LoraLoader']['input']['required']['lora_name'][0] ?? [];
                $loraCount = count($loras);
                
                $this->line("  <fg=green>✓</> LoRA loader endpoint OK");
                $this->line("    Available LoRAs: {$loraCount}");
                
                if ($loraCount > 0 && $loraCount <= 10) {
                    foreach ($loras as $lora) {
                        $this->line("      - {$lora}");
                    }
                } elseif ($loraCount > 10) {
                    for ($i = 0; $i < 5; $i++) {
                        $this->line("      - {$loras[$i]}");
                    }
                    $this->line("      ... and " . ($loraCount - 5) . " more");
                }
            } else {
                $this->line("  <fg=red>✗</> LoRA loader endpoint failed: {$response->status()}");
            }
            $this->line("");

            // Test history endpoint if prompt-id provided
            $promptId = $this->option('prompt-id');
            if ($promptId) {
                $this->info("📋 Testing History for Prompt: {$promptId}");
                $this->line("");
                
                $historyUrl = rtrim($serverUrl, '/') . '/history/' . $promptId;
                $this->line("  Testing: {$historyUrl}");
                
                $response = Http::timeout(10)->get($historyUrl);
                
                if ($response->successful()) {
                    $history = $response->json();
                    
                    $this->line("  <fg=green>✓</> History endpoint OK");
                    $this->line("");
                    $this->line("  <fg=yellow>Raw Response:</>");
                    $this->line(json_encode($history, JSON_PRETTY_PRINT));
                    
                    if (isset($history[$promptId])) {
                        $promptHistory = $history[$promptId];
                        $this->line("");
                        $this->line("  <fg=green>Found prompt data!</>");
                        $this->line("  Status: " . json_encode($promptHistory['status'] ?? 'N/A'));
                        
                        if (isset($promptHistory['outputs'])) {
                            $this->line("  Outputs:");
                            foreach ($promptHistory['outputs'] as $nodeId => $output) {
                                $this->line("    Node {$nodeId}:");
                                if (isset($output['images'])) {
                                    foreach ($output['images'] as $img) {
                                        $this->line("      Image: {$img['filename']} (type: {$img['type']}, subfolder: " . ($img['subfolder'] ?? 'none') . ")");
                                    }
                                }
                            }
                        }
                    } else {
                        $this->line("");
                        $this->line("  <fg=yellow>⚠</> No data for prompt_id in response");
                        $this->line("  Response keys: " . implode(', ', array_keys($history)));
                    }
                } else {
                    $this->line("  <fg=red>✗</> History endpoint failed: {$response->status()}");
                    $this->line("  Response: {$response->body()}");
                }
            }

            $this->line("");
            $this->info("✅ ComfyUI connection test complete");
            return 0;

        } catch (\Exception $e) {
            $this->error("❌ Connection test failed: {$e->getMessage()}");
            return 1;
        }
    }
}
