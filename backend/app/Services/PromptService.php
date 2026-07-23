<?php

namespace App\Services;

use App\Models\Prompt;
use App\Models\PromptType;
use Illuminate\Support\Facades\Log;

class PromptService
{
    /**
     * Get a prompt by type name and version
     *
     * @param string $typeName
     * @param int|null $version If null, gets the latest active version
     * @return string|null
     */
    public function getPrompt(string $typeName, ?int $version = null): ?string
    {
        $promptType = PromptType::where('name', $typeName)->first();
        
        if (!$promptType) {
            Log::warning("Prompt type not found", ['type' => $typeName]);
            return null;
        }

        $query = $promptType->prompts()->active();
        
        if ($version) {
            $query->where('version', $version);
        } else {
            $query->latest('version');
        }

        $prompt = $query->first();
        
        if (!$prompt) {
            Log::warning("No active prompt found", [
                'type' => $typeName,
                'version' => $version
            ]);
            return null;
        }

        Log::info("Retrieved prompt from database", [
            'type' => $typeName,
            'version' => $prompt->version,
            'prompt_id' => $prompt->id
        ]);

        return $prompt->text;
    }

    /**
     * Get a prompt with variable substitution
     *
     * @param string $typeName
     * @param array $variables
     * @param int|null $version
     * @return string|null
     */
    public function getPromptWithVariables(string $typeName, array $variables = [], ?int $version = null): ?string
    {
        $promptText = $this->getPrompt($typeName, $version);
        
        if (!$promptText) {
            return null;
        }

        // Replace variables in the format {variable_name}
        foreach ($variables as $key => $value) {
            $promptText = str_replace('{' . $key . '}', $value, $promptText);
        }

        return $promptText;
    }
}
