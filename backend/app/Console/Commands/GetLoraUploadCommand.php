<?php

namespace App\Console\Commands;

use App\Models\AiModel;
use App\Services\ComfyUIService;
use Illuminate\Console\Command;

class GetLoraUploadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'lora:upload-info 
                            {model_id : The ID of the AI model}
                            {--pem= : Path to PEM file for SSH}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get SCP command to upload LoRA to ComfyUI server';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $modelId = (int) $this->argument('model_id');
        $pemPath = $this->option('pem');

        // Find the AI model
        $aiModel = AiModel::find($modelId);

        if (!$aiModel) {
            $this->error("❌ AI Model with ID {$modelId} not found");
            return 1;
        }

        $comfyUIService = app(ComfyUIService::class);
        $serverUrl = config('services.comfyui.server_url');
        
        // Parse server URL to get IP
        $parsedUrl = parse_url($serverUrl);
        $serverIp = $parsedUrl['host'] ?? '3.11.174.151';

        $loraFilename = $comfyUIService->getLoraFilename($aiModel);
        $remotePath = "/home/ubuntu/ComfyUI/models/loras/{$loraFilename}";

        $this->line("");
        $this->info("📋 LoRA Upload Information for AI Model #{$modelId}");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        
        $this->line("  <fg=cyan>Model Name:</> {$aiModel->name}");
        $this->line("  <fg=cyan>Model ID:</> {$aiModel->id}");
        $this->line("  <fg=cyan>Status:</> {$aiModel->status}");
        $this->line("  <fg=cyan>HuggingFace Model:</> " . ($aiModel->huggingface_model_id ?? 'N/A'));
        $this->line("");
        
        $this->info("📁 LoRA File Details");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        $this->line("  <fg=cyan>Expected Filename:</> <fg=yellow>{$loraFilename}</>");
        $this->line("  <fg=cyan>Remote Path:</> {$remotePath}");
        $this->line("  <fg=cyan>ComfyUI Server:</> {$serverUrl}");
        $this->line("");

        $this->info("📥 Step 1: Download from HuggingFace (if needed)");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        if ($aiModel->huggingface_model_id) {
            $hfUrl = "https://huggingface.co/{$aiModel->huggingface_model_id}/resolve/main/lora.safetensors";
            $this->line("  <fg=green>curl -L -o {$loraFilename} \"{$hfUrl}\"</>");
        } else {
            $this->line("  <fg=gray>(No HuggingFace model ID available)</>");
        }
        $this->line("");

        $this->info("📤 Step 2: Upload to ComfyUI Server");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        $scpCommand = "scp -i {$pemPath} {$loraFilename} ubuntu@ec2-{$this->ipToDash($serverIp)}.eu-west-2.compute.amazonaws.com:{$remotePath}";
        $this->line("  <fg=green>{$scpCommand}</>");
        $this->line("");
        
        // Alternative with direct IP
        $this->line("  <fg=gray>Or using IP directly:</>");
        $scpCommandIp = "scp -i {$pemPath} {$loraFilename} ubuntu@{$serverIp}:{$remotePath}";
        $this->line("  <fg=green>{$scpCommandIp}</>");
        $this->line("");

        $this->info("✅ Step 3: Verify LoRA is Available");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        $this->line("  <fg=green>curl {$serverUrl}/object_info/LoraLoader | grep \"{$loraFilename}\"</>");
        $this->line("");
        
        // Check if LoRA exists
        $this->info("🔍 Current Status on ComfyUI Server");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("");
        
        try {
            $exists = $comfyUIService->loraExists($loraFilename);
            if ($exists) {
                $this->line("  <fg=green>✅ LoRA '{$loraFilename}' is available on ComfyUI server</>");
            } else {
                $this->line("  <fg=red>❌ LoRA '{$loraFilename}' NOT found on ComfyUI server</>");
                $this->line("  <fg=yellow>   Please upload using the SCP command above</>");
            }
        } catch (\Exception $e) {
            $this->line("  <fg=yellow>⚠️  Could not connect to ComfyUI server: {$e->getMessage()}</>");
        }
        
        $this->line("");

        return 0;
    }

    /**
     * Convert IP address to dash format for AWS DNS
     */
    private function ipToDash(string $ip): string
    {
        return str_replace('.', '-', $ip);
    }
}
