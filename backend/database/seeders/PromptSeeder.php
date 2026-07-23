<?php

namespace Database\Seeders;

use App\Models\Prompt;
use App\Models\PromptType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class PromptSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Log::info('PromptSeeder started');

        // Create prompt types
        $promptTypes = [
            [
                'name' => 'visualization_check',
                'description' => 'Prompt for checking if a description is visualizable',
            ],
            [
                'name' => 'negative',
                'description' => 'Negative prompts to avoid certain elements in thumbnails',
            ],
            [
                'name' => 'style',
                'description' => 'Style prompts for thumbnail generation',
            ],
            [
                'name' => 'default_style',
                'description' => 'Default style prompt for high-quality thumbnail generation',
            ],
            [
                'name' => 'general',
                'description' => 'General prompts for thumbnail generation',
            ],
        ];

        foreach ($promptTypes as $typeData) {
            $promptType = PromptType::firstOrCreate(
                ['name' => $typeData['name']],
                $typeData
            );

            Log::info('Created/found prompt type', [
                'id' => $promptType->id,
                'name' => $promptType->name,
            ]);
        }

        // Create prompts from existing files
        $this->createPromptsFromFiles();

        Log::info('PromptSeeder completed successfully');
    }

    /**
     * Create prompts from existing prompt files
     */
    private function createPromptsFromFiles(): void
    {
        // Visualization check prompt
        $visualizationCheckType = PromptType::where('name', 'visualization_check')->first();
        if ($visualizationCheckType) {
            $visualizationCheckText = "Take this description and enhance it into visualizable scene that can be turned into a thumbnail image. If the description is a project, idea, or abstract topic that cannot be directly visualized, suggest a specific visualizable scene that represents that project. Don't include any people in the scene.Return only the visualizable scene description, no commentary or explanation.

Description: {description}";

            Prompt::firstOrCreate([
                'prompt_type_id' => $visualizationCheckType->id,
                'version' => 1,
            ], [
                'title' => 'Visualization Check',
                'text' => $visualizationCheckText,
                'is_active' => true,
            ]);

            Log::info('Created visualization_check prompt');
        }

        // Style prompts
        $styleType = PromptType::where('name', 'style')->first();
        if ($styleType) {
            // Face-Driven / Reaction Style
            // $faceDrivenText = "Create a face-driven thumbnail featuring a person with an exaggerated, emotional expression (shocked, surprised, excited, or amazed).
            //     The face should take up 40-60% of the thumbnail space.
            //     Use dramatic lighting with strong shadows and highlights.
            //     Include bold, contrasting text overlay.";

            // Prompt::firstOrCreate([
            //     'prompt_type_id' => $styleType->id,
            //     'version' => 1
            // ], [
            //     'text' => $faceDrivenText,
            //     'is_active' => true
            // ]);

            // Cinematic / Movie Poster Style
            $cinematicText = 'The image style should be a cinematic movie poster-style thumbnail with dramatic composition, deep shadows, and cinematic lighting. 
                Use a dark, moody color palette with selective bright highlights. 
                Create depth and atmosphere.';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 2,
            ], [
                'title' => 'Cinematic',
                'text' => $cinematicText,
                'is_active' => true,
            ]);

            // MrBeast Style
            $mrBeast = 'Comic style, high-key front lighting, glowing highlights on the face, exaggerated contrast, bright rim light , high-gloss reflections, and a sense of extreme suspense; bold graphic look with 
comic-book-inspired shading, dynamic composition, symmetrical framing, sharp focus, HDR, glossy finish, 3D pop effect, YouTube MrBeast thumbnail aesthetic';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 3,
            ], [
                'title' => 'MrBeast',
                'text' => $mrBeast,
                'is_active' => true,
            ]);

            // Clickbait / Extreme Contrast Style
            // Use bold, attention-grabbing typography with thick outlines.
            $clickbaitText = 'The image style should be an extreme contrast clickbait-style thumbnail with vibrant, saturated colors (bright reds, yellows, oranges). 
                 Include dramatic expressions, arrows, or attention-grabbing elements. 
                 Make it loud and impossible to ignore.';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 4,
            ], [
                'title' => 'Clickbait',
                'text' => $clickbaitText,
                'is_active' => true,
            ]);

            // Illustrated / Cartoon Style
            $illustratedText = 'The image style should be an illustrated, cartoon-style thumbnail with bold, colorful illustrations. 
                Use flat design elements, bright colors. Include cartoon characters, icons, or illustrated elements. 
                Make it fun, approachable, and visually engaging.';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 5,
            ], [
                'title' => 'Illustrated Cartoon',
                'text' => $illustratedText,
                'is_active' => true,
            ]);

            // Symbolic / Abstract Style
            $symbolicText = 'The image style should be symbolic, abstract thumbnail using metaphors, symbols, and projectual imagery. 
                Use artistic composition with meaningful visual elements that represent the project. 
                Employ creative use of color, shape, and form. Focus on visual storytelling through symbols.';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 6,
            ], [
                'title' => 'Symbolic Abstract',
                'text' => $symbolicText,
                'is_active' => true,
            ]);

            // Symbolic / Abstract Style
            $photoRealistic = 'Make it photo-realistic, engaging, and optimized for mobile viewing.';

            Prompt::firstOrCreate([
                'prompt_type_id' => $styleType->id,
                'version' => 7,
            ], [
                'title' => 'Photo Realistic Style',
                'text' => $photoRealistic,
                'is_active' => true,
            ]);

            Log::info('Created 6 style prompts', [
                'styles' => [
                    'Face-Driven / Reaction Style (v1)',
                    'Cinematic / Movie Poster Style (v2)',
                    'Clean / Minimalist Style (v3)',
                    'Clickbait / Extreme Contrast Style (v4)',
                    'Illustrated / Cartoon Style (v5)',
                    'Symbolic / Abstract Style (v6)',
                ],
            ]);
        }

        // General prompts
        $generalType = PromptType::where('name', 'general')->first();
        if ($generalType) {
            $generalText = 'The thumbnail should be eye-catching, professional, and optimized for click-through rates. 

Requirements:
- Professional quality
- Optimized for mobile viewing
- Engaging and click-worthy design';

            Prompt::firstOrCreate([
                'prompt_type_id' => $generalType->id,
                'version' => 1,
            ], [
                'title' => 'General Thumbnail Requirements',
                'text' => $generalText,
                'is_active' => true,
            ]);

            Log::info('Created general prompt');
        }

        // Negative prompts
        $negativeType = PromptType::where('name', 'negative')->first();
        if ($negativeType) {
            $negativeText = 'No borders, no device mockups, no laptops, no frames. Avoid: extra limbs, extra fingers, distorted body, blurry face, deformed hands, bad anatomy, unnatural pose,blurry, low quality, distorted, pixelated, watermark, signature, text overlay, logo, brand name, copyrighted content, inappropriate content, violence, gore, explicit material, poor lighting, dark, unclear, confusing composition, cluttered design, amateur artwork';

            Prompt::firstOrCreate([
                'prompt_type_id' => $negativeType->id,
                'version' => 1,
            ], [
                'title' => 'Negative Elements to Avoid',
                'text' => $negativeText,
                'is_active' => true,
            ]);

            Log::info('Created negative prompt');
        }

        // Default style prompt
        $defaultStyleType = PromptType::where('name', 'default_style')->first();
        if ($defaultStyleType) {
            $defaultStyleText = 'Comic style, high-key front lighting, glowing highlights on the face, exaggerated contrast, bright rim light , high-gloss reflections, and a sense of extreme suspense; bold graphic look with comic-book-inspired shading, dynamic composition, symmetrical framing, sharp focus, HDR, glossy finish, 3D pop effect, YouTube MrBeast thumbnail aesthetic';

            Prompt::firstOrCreate([
                'prompt_type_id' => $defaultStyleType->id,
                'version' => 1,
            ], [
                'title' => 'Default Professional Style',
                'text' => $defaultStyleText,
                'is_active' => true,
            ]);

            Log::info('Created default_style prompt');
        }
    }
}
