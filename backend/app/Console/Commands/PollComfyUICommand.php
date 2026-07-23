<?php

namespace App\Console\Commands;

use App\Jobs\PollComfyUIJob;
use App\Models\Thumbnail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PollComfyUICommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'comfy:poll {thumbnail_id : The ID of the thumbnail to poll for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a PollComfyUIJob for a specific thumbnail';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $thumbnailId = $this->argument('thumbnail_id');
        
        // Validate thumbnail exists
        $thumbnail = Thumbnail::find($thumbnailId);
        
        if (!$thumbnail) {
            $this->error("Thumbnail with ID {$thumbnailId} not found.");
            return 1;
        }
        
        if (empty($thumbnail->comfy_prompt_id)) {
            $this->error("Thumbnail with ID {$thumbnailId} does not have a comfy_prompt_id.");
            return 1;
        }
        
        $this->info("Dispatching PollComfyUIJob for thumbnail ID: {$thumbnailId}");
        $this->info("ComfyUI Prompt ID: {$thumbnail->comfy_prompt_id}");
        $this->info("Status: {$thumbnail->status}");
        
        Log::info("PollComfyUICommand: Dispatching PollComfyUIJob", [
            'thumbnail_id' => $thumbnailId,
            'comfy_prompt_id' => $thumbnail->comfy_prompt_id,
            'status' => $thumbnail->status
        ]);
        
        // Dispatch the job
        PollComfyUIJob::dispatch($thumbnailId);
        
        $this->info("PollComfyUIJob dispatched successfully!");
        
        return 0;
    }
}
