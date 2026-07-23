<?php

namespace App\Jobs;

use App\Models\Script;
use App\Services\AnthropicService;
use App\Services\CreditService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class UpdateScriptJob implements ShouldQueue
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
        
        Log::info('UpdateScriptJob constructor called', [
            'script_id' => $this->scriptId,
            'script_id_type' => gettype($this->scriptId),
            'script_id_value' => var_export($this->scriptId, true)
        ]);
    }

    /**
     * Execute the job.
     */
    public function handle(AnthropicService $anthropicService): void
    {
        $startTime = microtime(true);
        
        Log::info('UpdateScriptJob started', [
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
            
            Log::info('Script record found for update', [
                'script_id' => $this->scriptId,
                'user_id' => $script->user_id,
                'current_status' => $script->status,
                'has_text' => !empty($script->text),
                'text_length' => strlen($script->text ?? ''),
                'prompt_length' => strlen($script->prompt ?? ''),
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

            // Ensure status is processing
            if ($script->status !== 'processing') {
                $script->update(['status' => 'processing']);
                Log::info('Script status updated to processing', [
                    'script_id' => $this->scriptId,
                    'previous_status' => $script->status
                ]);
            }

            // Get the current script text
            $currentScriptText = $script->text ?? '';
            
            if (empty($currentScriptText)) {
                Log::warning('Script has no existing text to update', [
                    'script_id' => $this->scriptId
                ]);
                
                // If there's no existing text, we can't update - mark as failed
                $script->update([
                    'status' => 'failed',
                    'error_message' => 'Cannot update script: No existing script text found'
                ]);
                
                return;
            }

            // Get the user's update instruction (the prompt)
            $userInstruction = $script->prompt ?? '';
            
            if (empty($userInstruction)) {
                Log::warning('Script has no prompt/instruction for update', [
                    'script_id' => $this->scriptId
                ]);
                
                $script->update([
                    'status' => 'failed',
                    'error_message' => 'Cannot update script: No update instruction (prompt) provided'
                ]);
                
                return;
            }

            // Build the update prompt
            $updatePrompt = $this->buildUpdatePrompt($currentScriptText, $userInstruction);
            
            Log::info('Built update prompt', [
                'script_id' => $this->scriptId,
                'current_script_length' => strlen($currentScriptText),
                'user_instruction_length' => strlen($userInstruction),
                'update_prompt_length' => strlen($updatePrompt)
            ]);

            // Call Anthropic to update the script
            $scriptData = $anthropicService->updateScript($updatePrompt);
            
            $generationTime = microtime(true) - $startTime;
            
            Log::info('Script update completed successfully', [
                'script_id' => $this->scriptId,
                'generation_time_seconds' => round($generationTime, 2),
                'script_length' => $scriptData['length'],
                'script_text_length' => strlen($scriptData['text']),
                'word_count' => str_word_count($scriptData['text'])
            ]);

            // Calculate word count
            $wordCount = str_word_count($scriptData['text']);
            
            // Update script with generated content
            $script->update([
                'text' => $scriptData['text'],
                'length' => $scriptData['length'],
                'word_count' => $wordCount,
                'status' => 'completed',
                'error_message' => null
            ]);

            $creditService = app(CreditService::class);
            $creditService->deductCreditsForOperation($script->user, CreditService::SCRIPT_UPDATE_OPERATION);

            $totalTime = microtime(true) - $startTime;
            
            Log::info('UpdateScriptJob completed successfully', [
                'script_id' => $this->scriptId,
                'total_time_seconds' => round($totalTime, 2),
                'generation_time_seconds' => round($generationTime, 2),
                'final_status' => 'completed',
                'script_length' => $scriptData['length']
            ]);

        } catch (ModelNotFoundException $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('UpdateScriptJob failed - Script not found', [
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
            
            Log::error('UpdateScriptJob failed with exception', [
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
     * Build the prompt for updating an existing script
     *
     * @param string $currentScriptText The current script text
     * @param string $userInstruction The user's instruction for how to update
     * @return string
     */
    private function buildUpdatePrompt(string $currentScriptText, string $userInstruction): string
    {
        $prompt = "You are an expert video scriptwriter. Below is an existing video script that needs to be updated.\n\n";
        $prompt .= "Please update the script according to the user's instruction below. ";
        $prompt .= "Maintain the same style, tone, and structure as the transcript unless the instruction specifically asks to change it. ";
        $prompt .= "Return only the updated script text, without any additional commentary or explanation.";
        $prompt .= "CURRENT SCRIPT:\n";
        $prompt .= "---\n";
        $prompt .= $currentScriptText;
        $prompt .= "\n---\n\n";
        $prompt .= "USER INSTRUCTION:\n";
        $prompt .= $userInstruction;
        $prompt .= ".\n\n";
        
        return $prompt;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $totalTime = 0; // Time tracking not available in failed handler
        
        Log::error('UpdateScriptJob permanently failed', [
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

