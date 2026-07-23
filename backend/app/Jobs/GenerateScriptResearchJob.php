<?php

namespace App\Jobs;

use App\Models\Script;
use App\Models\ScriptResearch;
use App\Services\PerplexityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateScriptResearchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $scriptId;
    public $timeout = 600; // 10 minutes timeout for Deep Research

    /**
     * Create a new job instance.
     */
    public function __construct($scriptId)
    {
        $this->scriptId = $scriptId;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        Log::info("GenerateScriptResearchJob started", ['script_id' => $this->scriptId]);

        try {
            // Resolve service manually to catch instantiation errors (e.g. missing API key)
            $perplexityService = app(PerplexityService::class);
            
            $script = Script::findOrFail($this->scriptId);

            // Create or update the research record with 'processing' status
            $research = $script->research()->updateOrCreate(
                ['script_id' => $script->id],
                [
                    'status' => 'processing',
                ]
            );

            // Perform the research
            $researchData = $perplexityService->performResearch($script->prompt, $script->title);

            // Update with results
            $research->update([
                'body' => $researchData['body'],
                'references' => $researchData['references'],
                'status' => 'completed',
            ]);

            Log::info("GenerateScriptResearchJob completed successfully", ['script_id' => $this->scriptId]);

        } catch (\Throwable $e) {
            Log::error("GenerateScriptResearchJob failed", [
                'script_id' => $this->scriptId,
                'error' => $e->getMessage()
            ]);

            // Update status to failed
            $script = Script::find($this->scriptId);
            if ($script) {
                $script->research()->updateOrCreate(
                    ['script_id' => $script->id],
                    [
                        'status' => 'failed',
                    ]
                );
            }

            throw $e;
        }
    }
}
