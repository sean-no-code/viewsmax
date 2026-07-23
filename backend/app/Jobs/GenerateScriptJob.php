<?php

namespace App\Jobs;

use App\Models\Script;
use App\Models\LibraryComponent;
use App\Services\ScriptHelper;
use App\Services\CreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GenerateScriptJob implements ShouldQueue
{
    use Queueable;

    public $scriptId;
    public $tries = 3;
    public $timeout = 900; // 15 minutes to allow for slow API responses

    /**
     * Create a new job instance.
     */
    public function __construct($scriptId)
    {
        $this->scriptId = $scriptId;
        
        Log::info('GenerateScriptJob constructor called', [
            'script_id' => $this->scriptId,
            'script_id_type' => gettype($this->scriptId),
            'script_id_value' => var_export($this->scriptId, true)
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(ScriptHelper $scriptHelper): void
    {
        $startTime = microtime(true);
        
        Log::info('GenerateScriptJob started', [
            'script_id' => $this->scriptId,
            'script_id_type' => gettype($this->scriptId),
            'script_id_value' => var_export($this->scriptId, true),
            'attempts' => $this->attempts(),
            'max_tries' => $this->tries,
            'timeout' => $this->timeout
        ]);

        try {
            // Find the script record
            $script = Script::findOrFail($this->scriptId);
            
            Log::info('Script record found', [
                'script_id' => $this->scriptId,
                'user_id' => $script->user_id,
                'current_status' => $script->status,
                'created_at' => $script->created_at
            ]);

            // Check if script already has a final status - if so, don't process again
            if (in_array($script->status, ['completed', 'failed'])) {
                Log::info('Script already has final status, skipping processing', [
                    'script_id' => $this->scriptId,
                    'current_status' => $script->status
                ]);
                return; // Exit early - job already completed or failed
            }

            // Check if script is already being processed
            // If status is 'processing', check how long it's been processing
            // Scripts are created with 'processing' status, so we need to allow the first run
            // but prevent duplicate processing from multiple job instances
            if ($script->status === 'processing') {
                $processingTimeSeconds = $script->updated_at ? now()->diffInSeconds($script->updated_at) : 0;
                $processingTimeMinutes = $script->updated_at ? now()->diffInMinutes($script->updated_at) : 0;
                
                // If it's been processing for less than 2 minutes, it might be:
                // 1. Just created (controller sets status to 'processing')
                // 2. Another job instance just claimed it
                // In either case, we'll use atomic update to claim it - only one will succeed
                // If it's been processing for 2-10 minutes, skip (likely another job is handling it)
                // If it's been processing for > 10 minutes, allow retry (might be stuck)
                if ($processingTimeMinutes >= 2 && $processingTimeMinutes < 10) {
                    Log::warning('Script is already being processed (recent update), skipping duplicate job', [
                        'script_id' => $this->scriptId,
                        'current_status' => $script->status,
                        'processing_time_minutes' => $processingTimeMinutes,
                        'processing_time_seconds' => $processingTimeSeconds,
                        'updated_at' => $script->updated_at
                    ]);
                    return; // Exit early - another job is likely handling it
                } elseif ($processingTimeMinutes >= 10) {
                    // It's been processing for a while - might be stuck, log and allow retry
                    Log::warning('Script has been processing for a long time, allowing retry', [
                        'script_id' => $this->scriptId,
                        'processing_time_minutes' => $processingTimeMinutes,
                        'updated_at' => $script->updated_at
                    ]);
                } else {
                    // Less than 2 minutes - might be first run or just created
                    // Continue to atomic update which will act as a lock
                    Log::info('Script recently set to processing, attempting to claim via atomic update', [
                        'script_id' => $this->scriptId,
                        'processing_time_seconds' => $processingTimeSeconds,
                        'updated_at' => $script->updated_at
                    ]);
                }
            }

            // Atomically update status to processing - only if not already completed/failed
            // Update updated_at timestamp to mark when processing started/continued
            // This acts as a lock - only one job instance will successfully update
            $updated = Script::where('id', $script->id)
                ->whereNotIn('status', ['completed', 'failed'])
                ->update(['status' => 'processing', 'updated_at' => now()]);
            
            if (!$updated) {
                // Another job instance might have claimed it, or status changed
                $script->refresh();
                Log::warning('Could not claim script for processing - status may have changed', [
                    'script_id' => $this->scriptId,
                    'current_status' => $script->status,
                    'updated_rows' => $updated
                ]);
                
                // If it's already completed or failed, exit
                if (in_array($script->status, ['completed', 'failed'])) {
                    Log::info('Script status changed to final status, skipping processing', [
                        'script_id' => $this->scriptId,
                        'final_status' => $script->status
                    ]);
                    return;
                }
                
                // If it's still processing and was recently updated (2-10 minutes), exit
                // This means another job instance successfully claimed it
                if ($script->status === 'processing') {
                    $processingTime = $script->updated_at ? now()->diffInMinutes($script->updated_at) : 0;
                    if ($processingTime >= 2 && $processingTime < 10) {
                        Log::info('Another job instance claimed the script, exiting', [
                            'script_id' => $this->scriptId,
                            'processing_time_minutes' => $processingTime
                        ]);
                        return;
                    }
                }
                
                // Otherwise, log and continue (might have been set to processing by this job or needs retry)
                Log::info('Continuing with script processing despite failed atomic update', [
                    'script_id' => $this->scriptId,
                    'current_status' => $script->status
                ]);
            }
            
            // Refresh script to get latest status
            $script->refresh();
            
            Log::info('Script claimed for processing', [
                'script_id' => $this->scriptId,
                'final_status' => $script->status,
                'updated_at' => $script->updated_at
            ]);

            // Generate script using ScriptHelper
            Log::info('Starting script generation', [
                'script_id' => $this->scriptId
            ]);

            // Get ALL transcriptions linked to this script (not filtering by processed_at)
            $allTranscriptions = DB::table('scripts_videos_transcriptions')
                ->where('script_id', $script->id)
                ->join('video_transcriptions', 'scripts_videos_transcriptions.video_transcription_id', '=', 'video_transcriptions.id')
                ->select('video_transcriptions.id', 'video_transcriptions.file_location', 'video_transcriptions.processed_at')
                ->get();

            // Verify ALL transcriptions are ready before proceeding
            $pendingTranscriptions = [];
            foreach ($allTranscriptions as $transcription) {
                if (empty($transcription->file_location) || empty($transcription->processed_at)) {
                    $pendingTranscriptions[] = $transcription->id;
                }
            }

            if (!empty($pendingTranscriptions)) {
                Log::warning('Cannot generate script - pending transcriptions exist, exiting gracefully', [
                    'script_id' => $script->id,
                    'pending_transcription_ids' => $pendingTranscriptions,
                    'total_transcriptions' => $allTranscriptions->count(),
                    'ready_transcriptions' => $allTranscriptions->count() - count($pendingTranscriptions)
                ]);
                
                // Keep status as 'processing' - job will be dispatched again when transcriptions are ready
                // Exit early without throwing exception so job doesn't fail and retry
                return;
            }

            // Get only the processed transcriptions with file locations
            $readyTranscriptions = DB::table('scripts_videos_transcriptions')
                ->where('script_id', $script->id)
                ->join('video_transcriptions', 'scripts_videos_transcriptions.video_transcription_id', '=', 'video_transcriptions.id')
                ->whereNotNull('video_transcriptions.processed_at')
                ->whereNotNull('video_transcriptions.file_location')
                ->select('video_transcriptions.file_location')
                ->get();

            $transcriptionContents = [];
            foreach ($readyTranscriptions as $transcription) {
                if (!empty($transcription->file_location)) {
                    // Read transcription from file
                    try {
                        $fileContent = Storage::disk('local')->get($transcription->file_location);
                        if ($fileContent !== false) {
                            $transcriptionContents[] = $fileContent;
                        } else {
                            Log::warning('Failed to read transcription file', [
                                'script_id' => $script->id,
                                'file_location' => $transcription->file_location
                            ]);
                            throw new \Exception('Transcription file not found: ' . $transcription->file_location);
                        }
                    } catch (\Exception $e) {
                        Log::error('Error reading transcription file', [
                            'script_id' => $script->id,
                            'file_location' => $transcription->file_location,
                            'error' => $e->getMessage()
                        ]);
                        throw new \Exception('Failed to read transcription file: ' . $e->getMessage());
                    }
                }
            }

            // Build the prompt using the new template format
            $research = $script->research;
            $components = $script->components;
            
            $basePrompt = "You are an expert video scriptwriter skilled at rewriting YouTube-style educational/entertaining scripts for different topics, \nwhile maintaining pacing, tone, structure, and emotional impact.\n\nUSER INSTRUCTION:\n{$script->prompt}";
            
            // Add Research Context if available
            if ($research && !empty($research->body)) {
                $basePrompt .= "\n\nRESEARCH CONTEXT (FACTS & DATA):\nUse the following research to ensure accuracy:\n" . $research->body . "\n\n";
            }

            // Add Components Context if available
            if ($components->isNotEmpty()) {
                $basePrompt .= "\n\nSCRIPT STRUCTURE & COMPONENTS:\nConstruct the script by logically connecting these specific components:\n";
                
                $hooks = $components->where('type', LibraryComponent::TYPE_HOOK);
                if ($hooks->isNotEmpty()) {
                    $basePrompt .= "\n[HOOKS - Choose one or combine]:\n";
                    foreach ($hooks as $hook) {
                        $basePrompt .= "- " . $hook->body . "\n";
                    }
                }

                $stories = $components->where('type', LibraryComponent::TYPE_STORY);
                if ($stories->isNotEmpty()) {
                    $basePrompt .= "\n[STORIES/CONTEXT - Use these narration points]:\n";
                    foreach ($stories as $story) {
                        $basePrompt .= "- " . $story->body . "\n";
                    }
                }

                $transitions = $components->where('type', LibraryComponent::TYPE_TRANSITION);
                if ($transitions->isNotEmpty()) {
                    $basePrompt .= "\n[TRANSITIONS - Use to bridge sections]:\n";
                    foreach ($transitions as $transition) {
                        $basePrompt .= "- " . $transition->body . "\n";
                    }
                }

                $ctas = $components->where('type', LibraryComponent::TYPE_CTA);
                if ($ctas->isNotEmpty()) {
                    $basePrompt .= "\n[CALLS TO ACTION - Must include]:\n";
                    foreach ($ctas as $cta) {
                        $basePrompt .= "- " . $cta->body . "\n";
                    }
                }
                
                $basePrompt .= "\n[OUTRO - If provided]:\n";
                $outros = $components->where('type', LibraryComponent::TYPE_OUTRO);
                 foreach ($outros as $outro) {
                        $basePrompt .= "- " . $outro->body . "\n";
                }
                
                $basePrompt .= "\nGenerate the smooth narrative flow to connect these components into a cohesive script.\n";
            }
            
            $preWarningConstraint = "";
            $postWarningConstraint = "";
            $maxWords = 0;

            // Calculate max words first if script_length is provided
            if (!empty($script->length)) {
                $maxWords = $script->length * 130;
            }

            // Task instructions to add at the end
            $taskInstructions = "\n\nYour task:\n1. Keep the **structure, style, pacing, and energy** of the original transcript.\n2. Keep the hooks, transitions, jokes, and calls-to-action in **the same order and tone.**\n3. Replace only the **product name, context, and examples** to match the new topic.\n4. Maintain a **natural human voice** that would sound good read aloud in a YouTube video.";
            $taskInstructions .= "\n5. Don't include any timestamps or comentary";
            
            // Add word count constraint if script_length is provided
            if (!empty($script->length)) {
                $preWarningConstraint = "\n\n*** CRITICAL LENGTH CONSTRAINT ***\nTarget Length: {$script->length} minutes (~{$maxWords} words).\nIf the source transcripts below are longer than this, you MUST aggressively summarize and condense content. DO NOT output a script longer than {$maxWords} words.\n**********************************\n\n";

                $postWarningConstraint = "\n\nCRITICAL CONSTRAINT: \n\nThe final script MUST be EXACTLY {$maxWords} words or fewer. This is a hard limit.\n\n- Count every word in your output before responding\n- If over {$maxWords} words, aggressively condense by removing filler phrases and redundant explanations\n- Prioritize keeping the hook, key transitions, and closing\n- Remove the least essential middle sections if needed to stay under {$maxWords} words";
                $taskInstructions .= $postWarningConstraint;
                
                Log::info('Word count constraint added to prompt', [
                    'script_id' => $script->id,
                    'script_length' => $script->length,
                    'max_words' => $maxWords
                ]);
            }
            
            // If there are video transcripts, add the instructions and include transcripts
            if (!empty($transcriptionContents)) {
                // Combine all transcripts first to check total length
                $allTranscripts = implode("\n\n", $transcriptionContents);
                $sourceWords = str_word_count($allTranscripts);
                
                $transcriptInstructions = "\n\nIf a video transcript is added\n\n";
                
                // Dynamic instructions based on length comparison
                $lengthRatio = $maxWords > 0 ? ($sourceWords / $maxWords) : 1.0;
                
                // TIER 1: HEAVY COMPRESSION (Ratio > 2.0)
                // Example: 3000 source -> 1300 target
                if ($maxWords > 0 && $lengthRatio >= 2.0) {
                     $transcriptInstructions .= "The source transcript provided below is MASSIVELY longer than your target length ({$sourceWords} words vs target {$maxWords} words).
                    
You MUST aggressively SUMMARIZE and CUT content.
- The input is DOUBLE the length of what you need.
- You cannot keep everything. You MUST leave out minor details and entire sections.
- Focus ONLY on the core message.
- Your output must be significant visually shorter than the input.";
                    
                    $taskInstructions = "\n\nYour task:
1. **PRESERVE THE STYLE**: Keep the original's **hooks, humor, tone, and pacing**.
2. **Aggressively CONDENSE** the *informational content* to fit the {$maxWords} word limit.
3. **Keep the Skeleton, Cut the Flesh**: Maintain the structural beats (Intro -> Hook -> Point 1 -> Transition -> Point 2), but minimize the examples and explanations within them.
4. **Strictly adhere** to the word limit.
5. Replace the product/context with the new topic.";
                }
                
                // TIER 2: SIGNIFICANT COMPRESSION (Ratio > 1.4)
                // Example: 3000 source -> 1950 target (Needs ~35% cut)
                elseif ($maxWords > 0 && $lengthRatio >= 1.4) {
                    $transcriptInstructions .= "The source transcript is significantly longer than your target length ({$sourceWords} words vs target {$maxWords} words).";
                    
                    $taskInstructions = "\n\nYour task:
1. **PRESERVE THE STYLE**: Keep the original's **hooks, humor, tone, and pacing and adapt the context and language**.
2. **Condense** the content to fit the {$maxWords} word limit.
3. **Keep the key transitions and jokes** in the same order.
4. Identify and **REMOVE** the least essential sections/examples. 
5. Maintain the flow and energy, but tighten every section.
6. Replace the product/context with the new topic.";
                }

                // TIER 3: LIGHT TRIMMING (Ratio > 1.1)
                // Example: 3000 source -> 2600 target (Needs ~15% cut)
                elseif ($maxWords > 0 && $lengthRatio > 1.1) {
                    $transcriptInstructions .= "The source transcript is slightly longer than your target length ({$sourceWords} words vs target {$maxWords} words).";
                    
                    $taskInstructions = "\n\nYour task:
1. **PRESERVE THE STYLE**: Keep the original's **hooks, humor, tone, and pacing and adapt the context and language**.
2. **Trim** the content to fit the {$maxWords} word limit.
3. Keep the original structure exactly, but **tighten the prose**.
4. Remove filler words and repetitive phrases but **keep the personality**.
5. Aim to be close to the target length but **do not exceed it**.
6. Replace the product/context with the new topic.";
                }

                // TIER 4: EXPANSION (Ratio < 0.9)
                // Example: 1000 source -> 1300 target
                elseif ($maxWords > 0 && $lengthRatio < 0.9) {
                    $transcriptInstructions .= "The source transcript provided is shorter than your target length ({$sourceWords} words vs target {$maxWords} words).";
                    
                    $taskInstructions = "\n\nYour task:
1. **ADAPT** the content, context, and language to the NEW TOPIC first. Do NOT write about the old topic.
2. **PRESERVE THE STYLE**: Use the source's structure as a **strict template** for hooks, transitions, and pacing.
3. **DEEPEN** the existing points to reach the target length. Add **more flesh to the skeleton**.
4. Add **detail and nuance** to the examples, but do NOT add unrelated sections.
5. **Target exactly {$maxWords} words.**
6. **CRITICAL:** The AI tendency is to over-write in this mode. Check your count. Do NOT exceed {$maxWords} words.
7. maintain a **natural human voice**.
8. Do NOT include meta-commentary.";
                }
                
                // TIER 5: NEUTRAL (Ratio 0.9 - 1.1)
                else {
                    $transcriptInstructions .= "The source transcript is similar in length to your target.";
                    $taskInstructions = "\n\nYour task:
1. **PRESERVE THE STYLE**: Recreate the transcript for the new topic, keeping all **hooks, humor, pacing, and structure** intact and adapt the context and language.
2. Keep the transitions, jokes, and calls-to-action in **the same order and tone.**
3. Replace only the **product name, context, and examples** to match the new topic.
4. Maintain a **natural human voice**.";
                }

                $taskInstructions .= "\n5. Don't include any timestamps or commentary.";
                $transcriptInstructions .= "\n\n  Aim for approximately {$maxWords} words. DO NOT exceed {$maxWords} words significantly or content will be lost.";
            
                
                $description = $basePrompt . $preWarningConstraint . $transcriptInstructions . "[transcripts]\n" . $allTranscripts . $taskInstructions;
                
                Log::info('Including transcriptions in script generation', [
                    'script_id' => $script->id,
                    'prompt_length' => strlen($script->prompt),
                    'transcriptions_count' => count($transcriptionContents),
                    'total_transcription_length' => strlen($allTranscripts),
                    'total_description_length' => strlen($description)
                ]);
            } else {
                // No transcripts - just use the base prompt with task instructions (and constraints)
                $description = $basePrompt . $preWarningConstraint . $taskInstructions;
                
                Log::info('No transcriptions available for script generation', [
                    'script_id' => $script->id,
                    'prompt_length' => strlen($script->prompt),
                    'total_description_length' => strlen($description)
                ]);
            }

            // Calculate max tokens with safety buffer (1.5x)
            // 1 word ~= 0.75 tokens -> Words / 0.75 = Base Tokens
            // Safety Buffer: Base Tokens * 1.5
            $maxTokens = $globalMaxTokens = (int) config('services.anthropic.max_output_tokens', 8192);
            // if (!empty($script->length)) {
            //     $targetWords = $script->length * 130;
            //     $baseTokens = $targetWords / 0.75;
            //     $calculatedMaxTokens = (int) ceil($baseTokens * 1.5);
                
            //     // Cap at the global max tokens limit from config
            //     $globalMaxTokens = (int) config('services.anthropic.max_output_tokens', 8192);
            //     $maxTokens = min($calculatedMaxTokens, $globalMaxTokens);
                
            //     // Log the calculation
            //     Log::info('Calculated dynamic max_tokens', [
            //         'script_id' => $script->id,
            //         'target_words' => $targetWords,
            //         'base_tokens' => $baseTokens,
            //         'max_tokens' => $maxTokens
            //     ]);
            // }

            // Use generateScriptWithFallback to attempt generation
            // But we'll check if fallback was used and fail the job if so
            $scriptData = $scriptHelper->generateScriptWithFallback($description, $maxTokens);
            
            $generationTime = microtime(true) - $startTime;
            
            $usedFallback = $scriptData['used_fallback'] ?? false;
            $errorMessage = $scriptData['error_message'] ?? null;
            
            // If fallback was used, mark script as failed and throw exception
            if ($usedFallback) {
                $errorMsg = 'Script Helper Error: ' . $errorMessage;
                
                Log::error('Script generation failed - OpenAI error occurred', [
                    'script_id' => $this->scriptId,
                    'generation_time_seconds' => round($generationTime, 2),
                    'error_message' => $errorMessage
                ]);
                
                // Update script status to failed
                $script->update([
                    'status' => 'failed',
                    'error_message' => $errorMsg
                ]);
                
                // Throw exception to stop job processing
                throw new \Exception($errorMsg);
            }
            
            Log::info('Script generation completed successfully', [
                'script_id' => $this->scriptId,
                'generation_time_seconds' => round($generationTime, 2),
                'script_length' => $scriptData['length'],
                'script_text_length' => strlen($scriptData['text']),
                'word_count' => str_word_count($scriptData['text'])
            ]);

            // Calculate word count
            $wordCount = str_word_count($scriptData['text']);
            
            // Update script with generated content (only if OpenAI succeeded)
            $script->update([
                'text' => $scriptData['text'],
                'length' => $scriptData['length'],
                'word_count' => $wordCount,
                'status' => 'completed',
                'error_message' => null
            ]);

            $creditService = app(CreditService::class);
            $creditService->deductCreditsForOperation($script->user, CreditService::SCRIPT_CREATE_OPERATION);

            $totalTime = microtime(true) - $startTime;
            
            Log::info('GenerateScriptJob completed successfully', [
                'script_id' => $this->scriptId,
                'total_time_seconds' => round($totalTime, 2),
                'generation_time_seconds' => round($generationTime, 2),
                'final_status' => 'completed',
                'script_length' => $scriptData['length']
            ]);

        } catch (ModelNotFoundException $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('GenerateScriptJob failed - Script not found', [
                'script_id' => $this->scriptId,
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'total_time_seconds' => round($totalTime, 2)
            ]);

            // Update script status to failed if it exists
            try {
                $script = Script::find($this->scriptId);
                if ($script) {
                    $script->update([
                        'status' => 'failed',
                        'error_message' => 'Script not found: ' . $e->getMessage()
                    ]);
                }
            } catch (\Exception $updateException) {
                Log::error('Failed to update script status after ModelNotFoundException', [
                    'script_id' => $this->scriptId,
                    'update_error' => $updateException->getMessage()
                ]);
            }

            throw $e;

        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('GenerateScriptJob failed with exception', [
                'script_id' => $this->scriptId,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_trace' => $e->getTraceAsString(),
                'total_time_seconds' => round($totalTime, 2),
                'attempts' => $this->attempts(),
                'max_tries' => $this->tries
            ]);

            // Update script status to failed
            try {
                $script = Script::find($this->scriptId);
                if ($script) {
                    $script->update([
                        'status' => 'failed',
                        'error_message' => $e->getMessage()
                    ]);
                }
            } catch (\Exception $updateException) {
                Log::error('Failed to update script status after exception', [
                    'script_id' => $this->scriptId,
                    'update_error' => $updateException->getMessage()
                ]);
            }

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $totalTime = microtime(true) - ($this->startTime ?? microtime(true));
        
        Log::error('GenerateScriptJob permanently failed', [
            'script_id' => $this->scriptId,
            'error_message' => $exception->getMessage(),
            'error_code' => $exception->getCode(),
            'error_file' => $exception->getFile(),
            'error_line' => $exception->getLine(),
            'total_time_seconds' => round($totalTime, 2),
            'final_attempts' => $this->attempts(),
            'max_tries' => $this->tries
        ]);

        // Update script status to failed
        try {
            $script = Script::find($this->scriptId);
            if ($script) {
                $script->update([
                    'status' => 'failed',
                    'error_message' => 'Job permanently failed: ' . $exception->getMessage()
                ]);
            }
        } catch (\Exception $updateException) {
            Log::error('Failed to update script status after job failure', [
                'script_id' => $this->scriptId,
                'update_error' => $updateException->getMessage()
            ]);
        }
    }
}
