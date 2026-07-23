<?php

namespace App\Services;

use App\Models\AiModel;
use Illuminate\Support\Facades\Log;

class ExpressionTransformerService
{
    /**
     * Extract only facial expression details from a full description
     * Removes clothing, background, hair, and body descriptions
     * Replaces person references with trigger word
     */
    public function extractFacialExpression(string $fullDescription, string $triggerWord = ''): string
    {
        // Split into sentences
        $sentences = preg_split('/(?<=[.!?])\s+/', $fullDescription);
        $facialSentences = [];

        // Keywords that indicate we WANT to keep this sentence (facial expressions)
        $keepKeywords = [
            'smiling', 'frowning', 'neutral', 'expression', 'emotion',
            'looking', 'eyes', 'eye', 'gaze', 'staring', 'glancing',
            'mouth', 'lips', 'teeth', 'grin',
            'head tilt', 'tilted', 'facing',
            'eyebrows', 'eyebrow', 'raised', 'furrowed',
            'surprised', 'happy', 'sad', 'angry', 'shocked', 'relaxed', 'friendly',
            'squinted', 'wide', 'closed',
        ];

        // Keywords that indicate we should SKIP this sentence entirely
        $skipKeywords = [
            'hair', 'hairstyle', 'styled', 'highlights', 'hairline', 'haircut',
            'wearing', 'wears', 'dressed', 'outfit',
            'shirt', 'jacket', 'suit', 'tie', 'collar', 'clothing', 'dress',
            'background', 'behind', 'curtain', 'wall', 'room',
            'goatee', 'mustache', 'beard', 'facial hair',
            'necklace', 'jewelry', 'accessories', 'watch', 'bracelet',
        ];

        foreach ($sentences as $sentence) {
            $lowerSentence = strtolower($sentence);

            // Skip if sentence contains skip keywords
            $shouldSkip = false;
            foreach ($skipKeywords as $skipWord) {
                if (stripos($lowerSentence, $skipWord) !== false) {
                    $shouldSkip = true;
                    break;
                }
            }

            if ($shouldSkip) {
                continue;
            }

            // Check if sentence contains facial expression keywords
            $hasFacialKeyword = false;
            foreach ($keepKeywords as $keyword) {
                if (stripos($lowerSentence, $keyword) !== false) {
                    $hasFacialKeyword = true;
                    break;
                }
            }

            if ($hasFacialKeyword) {
                $facialSentences[] = trim($sentence);
            }
        }

        // If we found facial expression sentences, combine them
        if (! empty($facialSentences)) {
            $result = implode(' ', $facialSentences);

            // Replace person references with trigger word if provided
            if (! empty($triggerWord)) {
                $result = preg_replace('/\b[Aa] (man|woman|person)\b/', $triggerWord, $result);
                $result = preg_replace('/\b[Tt]he (man|woman|person)\b/', $triggerWord, $result);
                $result = preg_replace('/\b[Hh]e is\b/', $triggerWord.' is', $result);
                $result = preg_replace('/\b[Ss]he is\b/', $triggerWord.' is', $result);
                $result = preg_replace('/\b[Hh]e has\b/', $triggerWord.' has', $result);
                $result = preg_replace('/\b[Ss]he has\b/', $triggerWord.' has', $result);
                $result = preg_replace('/\b[Hh]e and\b/', $triggerWord.' and', $result);
                $result = preg_replace('/\b[Ss]he and\b/', $triggerWord.' and', $result);
                $result = preg_replace('/\b[Hh]is\b/', $triggerWord.'\'s', $result);
                $result = preg_replace('/\b[Hh]er\b/', $triggerWord.'\'s', $result);

                // Also handle possessive forms at start of sentence
                $result = preg_replace('/\bHis (eyes|eyebrows|mouth|lips|face|expression)\b/', $triggerWord.'\'s $1', $result);
                $result = preg_replace('/\bHer (eyes|eyebrows|mouth|lips|face|expression)\b/', $triggerWord.'\'s $1', $result);
            }

            // Clean up any remaining age references
            $result = preg_replace('/,?\s*(and\s+)?(appears to be|in|about|around|approximately)\s+(in\s+)?(his|her|their|the)\s+(late|early|mid-?)?\s*\d+s?(\s*(or|to)\s*\d+s?)?/i', '', $result);
            $result = preg_replace('/\b(late|early|mid-?)\s*\d+s(\s*or\s*\d+s)?\b/i', '', $result);

            // Clean up extra spaces
            $result = preg_replace('/\s+/', ' ', $result);
            $result = preg_replace('/\s*,\s*,+/', ',', $result);
            $result = trim($result);

            // If result is too short after cleaning, return a default
            if (strlen($result) < 10) {
                return ! empty($triggerWord)
                    ? "{$triggerWord} has neutral expression, looking at camera"
                    : 'neutral expression, looking at camera';
            }

            return $result;
        }

        // Fallback: return default neutral expression
        return ! empty($triggerWord)
            ? "{$triggerWord} has neutral expression, looking at camera"
            : 'neutral expression, looking at camera';
    }

    /**
     * Build a complete prompt for copy thumbnail with all model characteristics
     *
     * Format:
     * - Base: {trigger word} same head shape and proportions, portrait photograph, natural lighting, high quality, photorealistic, detailed face, preserve original head size and angle
     * - If bald: {trigger word} completely bald head, no hair whatsoever, hairless scalp, shiny bald head, bald {gender} with smooth scalp, {ethnicity},
     * - Then: MATCH ONLY THE FACIAL EXPRESSION: {extracted expression}
     */
    public function buildCompletePrompt(string $extractedExpression, AiModel $aiModel, $copyThumbnail = null): string
    {
        $triggerWord = $aiModel->triggerWord();
        $gender = $aiModel->aiModelType?->name ?? 'person'; // 'male' or 'female'
        $ethnicity = $aiModel->ethnicity?->name ?? '';
        $age = $aiModel->age;

        // Get simple ethnicity term
        $ethnicityTerm = $this->getSimpleEthnicityTerm($ethnicity);

        Log::info('ExpressionTransformerService::buildCompletePrompt', [
            'ai_model_id' => $aiModel->id,
            'trigger_word' => $triggerWord,
            'is_bald' => $aiModel->bald,
            'gender' => $gender,
            'ethnicity' => $ethnicity,
            'age' => $age,
            'has_copy_thumbnail' => $copyThumbnail !== null,
        ]);

        // Extract only facial expression from the full description, passing trigger word
        $facialExpression = $this->extractFacialExpression($extractedExpression, $triggerWord);

        Log::info('Extracted facial expression only', [
            'original_length' => strlen($extractedExpression),
            'facial_expression_length' => strlen($facialExpression),
            'original_preview' => substr($extractedExpression, 0, 150),
            'facial_preview' => substr($facialExpression, 0, 150),
        ]);

        // Build character descriptor parts
        $characterParts = [];

        // Add age if available
        if ($age) {
            $characterParts[] = "a {$age} year old";
        }

        // Add ethnicity if available
        if (! empty($ethnicityTerm)) {
            $characterParts[] = $ethnicityTerm;
        }

        // Add gender
        $characterParts[] = $gender;

        // Add bald status if true
        if ($aiModel->bald) {
            $characterParts[] = 'bald';
        }

        $characterDescription = ! empty($characterParts) ? implode(', ', $characterParts) : $gender;

        // Base prompt - ALWAYS included
        $basePrompt = "{$triggerWord}, same head shape and proportions, portrait photograph, natural lighting, high quality, photorealistic, detailed face, preserve original head size and angle";

        // If model is bald, add bald-specific description at the beginning
        // Use emphasis weights (term:1.8) to strongly override any hair from reference images
        if ($aiModel->bald) {
            // CRITICAL: Use strong emphasis weights to force baldness override
            // Flux model will otherwise copy hair from reference images
            $baldDescription = "{$triggerWord}, (completely bald head:1.8), (no hair whatsoever:1.8), (hairless scalp:1.8), (shiny bald head:1.6), (smooth bald scalp:1.6), (bald {$gender}:1.5), zero hair on head, 100% bald";

            // Add ethnicity if available
            if (! empty($ethnicityTerm)) {
                $baldDescription .= ", {$ethnicityTerm}";
            }

            // Add age if available
            if ($age) {
                $baldDescription .= ", {$age} years old";
            }

            // Construct final prompt with bald description FIRST for maximum priority
            $finalPrompt = "{$baldDescription}, {$basePrompt}, MATCH ONLY THE FACIAL EXPRESSION: {$facialExpression}";
        } else {
            // Non-bald: base prompt + character description + facial expression
            // Add explicit hair description to ensure the model generates hair
            $hairDescription = 'with natural hair, full head of hair';
            $finalPrompt = "{$basePrompt}, {$characterDescription}, {$hairDescription}, MATCH ONLY THE FACIAL EXPRESSION: {$facialExpression}";
        }

        // Append style and general prompts if CopyThumbnail is provided and has prompt models
        if ($copyThumbnail && method_exists($copyThumbnail, 'getCombinedPromptText')) {
            $additionalPrompts = $copyThumbnail->getCombinedPromptText();
            if (! empty($additionalPrompts)) {
                // Add a period and space before appending
                $finalPrompt .= '. '.$additionalPrompts;

                Log::info('Appended style and general prompts from prompt models', [
                    'additional_prompts_length' => strlen($additionalPrompts),
                    'style_prompt_id' => $copyThumbnail->style_prompt_id,
                    'general_prompt_id' => $copyThumbnail->general_prompt_id,
                ]);
            }
        }

        Log::info('Complete prompt built', [
            'final_length' => strlen($finalPrompt),
            'character_description' => $characterDescription,
            'preview' => substr($finalPrompt, 0, 250),
        ]);

        return $finalPrompt;
    }

    /**
     * Get a simple ethnicity term for the prompt
     */
    private function getSimpleEthnicityTerm(string $ethnicity): string
    {
        $terms = [
            'White' => 'white guy',
            'Black' => 'black person',
            'East Asian (Chinese, Japanese, Korean)' => 'asian person',
            'South Asian (Indian)' => 'south asian person',
            'Hispanic' => 'hispanic person',
            'Middle Eastern (Arabic)' => 'middle eastern person',
            'South East Asian (Thai, Indonesian)' => 'southeast asian person',
            'Pacific (Polynesian)' => 'polynesian person',
            'Afro-Asian (half black, half Asian)' => 'afro-asian person',
            'Afro-European (half white, half black)' => 'mixed race person',
            'Asian American' => 'asian american person',
            'Eurasian (half white, half Asian)' => 'eurasian person',
        ];

        return $terms[$ethnicity] ?? '';
    }

    /**
     * Legacy method - kept for backwards compatibility but now just calls buildCompletePrompt
     *
     * @deprecated Use buildCompletePrompt directly
     */
    public function transformForAiModel(string $expression, AiModel $aiModel): string
    {
        return $this->buildCompletePrompt($expression, $aiModel);
    }
}
