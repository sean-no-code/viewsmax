<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class ScriptHelper
{
    private AnthropicService $anthropicService;

    public function __construct(AnthropicService $anthropicService)
    {
        $this->anthropicService = $anthropicService;
    }

    /**
     * Generate a video script using Claude
     *
     * @param string $project The video project/topic
     * @return array
     */
    public function generateScript(string $project, ?int $maxTokens = null): array
    {
        $startTime = microtime(true);
        
        Log::info('ScriptHelper::generateScript started', [
            'project' => $project,
            'project_length' => strlen($project)
        ]);

        try {
            $result = $this->anthropicService->generateScript($project, $maxTokens);
            
            $totalTime = microtime(true) - $startTime;
            
            Log::info('ScriptHelper::generateScript completed successfully', [
                'project' => $project,
                'script_length' => $result['length'],
                'script_text_length' => strlen($result['text']),
                'word_count' => str_word_count($result['text']),
                'generation_time_seconds' => round($totalTime, 2)
            ]);

            return $result;
        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::error('ScriptHelper::generateScript failed', [
                'project' => $project,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'generation_time_seconds' => round($totalTime, 2)
            ]);

            throw $e;
        }
    }


    /**
     * Generate a script with fallback for testing/development
     *
     * @param string $project
     * @return array
     */
    public function generateScriptWithFallback(string $project, ?int $maxTokens = null): array
    {
        $startTime = microtime(true);
        
        Log::info('ScriptHelper::generateScriptWithFallback started', [
            // 'project' => $project,
            'project_length' => strlen($project)
        ]);

        try {
            $result = $this->anthropicService->generateScript($project, $maxTokens);
            
            $totalTime = microtime(true) - $startTime;
            
            Log::debug('ScriptHelper::generateScriptWithFallback completed successfully', [
                'project' => $project,
                'script_length' => $result['length'],
                'script_text_length' => strlen($result['text']),
                'word_count' => str_word_count($result['text']),
                'generation_time_seconds' => round($totalTime, 2),
                'used_fallback' => false
            ]);

            // Ensure result has used_fallback flag
            $result['used_fallback'] = false;
            $result['error_message'] = null;
            
            return $result;
        } catch (\Exception $e) {
            $totalTime = microtime(true) - $startTime;
            
            Log::warning('Script generation failed, using fallback', [
                'project' => $project,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'generation_time_seconds' => round($totalTime, 2)
            ]);
            
            $fallbackScript = "How to Master {$project} - Complete Guide

Did you know that 90% of people get {$project} completely wrong? In the next 5 minutes, I'll show you the exact method that works.

Here are the key points we'll cover:
1. The #1 mistake everyone makes with {$project}
2. The step-by-step process that actually works
3. Real examples and case studies
4. Common pitfalls to avoid
5. How to measure your success

Now you have everything you need to master {$project}. If this helped you, hit subscribe for more actionable content like this.";
            
            $fallbackResult = [
                'text' => $fallbackScript,
                'length' => round(str_word_count($fallbackScript) / 130),
                'used_fallback' => true,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode()
            ];

            $totalTime = microtime(true) - $startTime;
            
            Log::error('ScriptHelper::generateScriptWithFallback completed with fallback due to Anthropic error', [
                'project' => $project,
                'script_length' => $fallbackResult['length'],
                'script_text_length' => strlen($fallbackResult['text']),
                'word_count' => str_word_count($fallbackResult['text']),
                'total_time_seconds' => round($totalTime, 2),
                'used_fallback' => true,
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine()
            ]);
            
            return $fallbackResult;
        }
    }
}

