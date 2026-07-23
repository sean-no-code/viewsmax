<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Flux 2 Service - Core workflow builder for Flux 2 image generation
 *
 * This service handles loading and manipulating the flux_2_with_refine.json workflow.
 * Key nodes in the workflow:
 * - Node 9: SaveImage - output
 * - Node 100: FluxGuidance - conditioning (from 118)
 * - Node 107: CLIP Text Encode - positive prompt
 * - Node 112: ReferenceLatent - base image reference (from 150)
 * - Node 118: ReferenceLatent - reference image reference (from 119)
 * - Node 121: LoadImage - reference image (image 2 - head source)
 * - Node 135: Float (Set Megapixels) - controls output resolution
 * - Node 151: LoadImage - base image (image 1 - body source)
 * - Node 156: LanPaint_KSampler - main sampler with steps, seed
 * - Node 161: LoraLoaderModelOnly - LoRA for generation
 * - Node 162: ComfySwitchNode - inpainting toggle (false=inpaint, true=empty latent)
 */
class Flux2Service
{
    private string $workflowPath;

    private array $config;

    public function __construct()
    {
        $this->workflowPath = app_path('Prompts/flux_2_with_refine.json');
        $this->config = config('services.flux2', []);
    }

    /**
     * Load the Flux 2 workflow template
     *
     * @throws \Exception
     */
    public function loadWorkflow(): array
    {
        if (! file_exists($this->workflowPath)) {
            throw new \Exception("Flux 2 workflow template not found at: {$this->workflowPath}");
        }

        $template = file_get_contents($this->workflowPath);
        $workflow = json_decode($template, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse Flux 2 workflow template: '.json_last_error_msg());
        }

        Log::info('Loaded Flux 2 workflow template', [
            'node_count' => count($workflow),
        ]);

        return $workflow;
    }

    /**
     * Set the megapixel value (controls output resolution)
     * Node 135: PrimitiveFloat
     */
    public function setMegapixel(array &$workflow, float $value): void
    {
        if (isset($workflow['135'])) {
            $workflow['135']['inputs']['value'] = $value;
            Log::debug('Set megapixel value', ['value' => $value]);
        }
    }

    /**
     * Set the number of steps for the main sampler
     * Node 156: LanPaint_KSampler
     */
    public function setSteps(array &$workflow, int $steps): void
    {
        if (isset($workflow['156'])) {
            $workflow['156']['inputs']['steps'] = $steps;
            Log::debug('Set sampler steps', ['steps' => $steps]);
        }
    }

    /**
     * Set a random seed for reproducibility/variation
     * Node 156: LanPaint_KSampler
     */
    public function setSeed(array &$workflow): int
    {
        $seed = random_int(1, 2147483647);

        if (isset($workflow['156'])) {
            $workflow['156']['inputs']['seed'] = $seed;
        }

        Log::debug('Set random seed', ['seed' => $seed]);

        return $seed;
    }

    /**
     * Enable or disable the refine pass
     * NOTE: Refine is no longer supported in the simplified workflow
     * This method is kept for API compatibility but does nothing
     */
    public function setRefineEnabled(array &$workflow, bool $enabled): void
    {
        // Refine is no longer supported in the simplified workflow
        // Output always comes from node 9
        Log::debug('Refine option ignored (not supported in current workflow)', ['enabled' => $enabled]);
    }

    /**
     * Enable or disable inpainting mode
     * Node 162: ComfySwitchNode - when switch is true, uses EmptyFlux2LatentImage
     * When false, uses the image with mask for inpainting
     */
    public function setInpaintingEnabled(array &$workflow, bool $enabled): void
    {
        if (isset($workflow['162'])) {
            // switch=true matches inpainting_enabled=true for consistency
            $workflow['162']['inputs']['switch'] = $enabled;
            Log::debug('Set inpainting', ['enabled' => $enabled, 'switch_value' => $enabled]);
        }
    }

    /**
     * Set the LoRA for head swap operations
     * Node 161: LoraLoaderModelOnly
     */
    public function setHeadSwapLoRA(array &$workflow, string $loraName): void
    {
        if (isset($workflow['161'])) {
            $workflow['161']['inputs']['lora_name'] = $loraName;
            Log::debug('Set head swap LoRA', ['lora' => $loraName]);
        }
    }

    /**
     * Set the LoRA for generation operations (uses same node as head swap)
     * Node 161: LoraLoaderModelOnly
     */
    public function setGenerationLoRA(array &$workflow, string $loraName): void
    {
        // Uses the same LoRA node as head swap in the simplified workflow
        $this->setHeadSwapLoRA($workflow, $loraName);
    }

    /**
     * Set the positive prompt text
     * Node 107: CLIPTextEncode
     */
    public function setPrompt(array &$workflow, string $prompt): void
    {
        if (isset($workflow['107'])) {
            $workflow['107']['inputs']['text'] = $prompt;
            Log::debug('Set prompt', ['prompt_length' => strlen($prompt)]);
        }
    }

    /**
     * Set the base image (image 1)
     * Node 151: LoadImage
     */
    public function setBaseImage(array &$workflow, string $filename): void
    {
        if (isset($workflow['151'])) {
            $workflow['151']['inputs']['image'] = $filename;
            Log::debug('Set base image', ['filename' => $filename]);
        }
    }

    /**
     * Set the reference image (image 2 - for head swap)
     * Node 121: LoadImage
     */
    public function setReferenceImage(array &$workflow, string $filename): void
    {
        if (isset($workflow['121'])) {
            $workflow['121']['inputs']['image'] = $filename;
            Log::debug('Set reference image', ['filename' => $filename]);
        }
    }

    /**
     * Set additional images (images 3, 4, 5)
     * NOTE: Additional images are no longer supported in the simplified workflow
     * This method is kept for API compatibility but does nothing
     */
    public function setAdditionalImages(array &$workflow, array $filenames): void
    {
        // Additional images are no longer supported in the simplified workflow
        // Only base image (151) and reference image (121) are used
        if (! empty($filenames)) {
            Log::warning('Additional images provided but not supported in current workflow', [
                'count' => count($filenames),
            ]);
        }
    }

    /**
     * Remove unused additional image nodes from workflow
     * NOTE: No longer needed - simplified workflow has no additional image nodes
     * This method is kept for API compatibility but does nothing
     */
    public function removeUnusedImageNodes(array &$workflow, int $additionalImageCount): void
    {
        // No longer needed - simplified workflow has no additional image nodes
        // Conditioning chain is fixed: 107 → 112 → 118 → 100
        Log::debug('removeUnusedImageNodes called but not needed for simplified workflow');
    }

    /**
     * Set the filename prefix for output
     * Node 9: SaveImage (only output node in simplified workflow)
     */
    public function setFilenamePrefix(array &$workflow, string $prefix): void
    {
        if (isset($workflow['9'])) {
            $workflow['9']['inputs']['filename_prefix'] = $prefix;
        }
        Log::debug('Set filename prefix', ['prefix' => $prefix]);
    }

    /**
     * Get the output node ID
     * NOTE: Simplified workflow only has node 9 as output
     */
    public function getOutputNodeId(bool $refineEnabled = false): string
    {
        // Simplified workflow only has node 9 as output
        return '9';
    }

    /**
     * Set the webhook URL for completion notification
     * Node 200: Webhook Notifier (requires ComfyUI-Notifications extension)
     */
    /**
     * Set the webhook URL for completion notification
     * Node 185: Notif-Webhook (preferred)
     * Node 200: Webhook Notifier (fallback)
     */
    /**
     * Set the webhook URL for completion notification
     * Node 185: Notif-Webhook (preferred)
     * Node 200: Webhook Notifier (fallback)
     */
    public function setWebhookUrl(array &$workflow, string $webhookUrl, string $promptId, int $imageId): void
    {
        // Only set webhook if the feature is enabled
        if (! $this->isWebhookEnabled()) {
            Log::info('Webhook feature is disabled, skipping webhook setup');

            return;
        }

        // Prepare the payload
        // For Node 185, we inject it into the json_format string
        // The user template is: {"prompt_id": "<notification_text>", "image_id":"123123"}
        // <notification_text> is replaced by the 'notification_text' input, which we can set to promptId

        if (isset($workflow['185'])) {
            // Notif-Webhook node
            $workflow['185']['inputs']['webhook_url'] = $webhookUrl;
            $workflow['185']['inputs']['verify_ssl'] = false;
            $workflow['185']['inputs']['timeout'] = 10; // 10 second timeout
            $workflow['185']['inputs']['mode'] = 'always';

            // Set promptId via notification_text (which maps to <notification_text>)
            $workflow['185']['inputs']['notification_text'] = $promptId;

            // Set image_id directly in the JSON format string
            $workflow['185']['inputs']['json_format'] = json_encode([
                'prompt_id' => '<notification_text>', // value will be substituted by node
                'image_id' => (string) $imageId,
                'status' => 'completed',
            ]);

            // Connect webhook to fire after VAEDecode (104) completes
            // Using 'any' input to trigger the webhook
            $workflow['185']['inputs']['any'] = ['104', 0];

            Log::info('Set webhook URL (Node 185)', [
                'url' => $webhookUrl,
                'image_id' => $imageId,
                'connected_to' => '104 (VAEDecode) via any input',
                'timeout' => 10,
            ]);
        } elseif (isset($workflow['200'])) {
            // Webhook Notifier node (fallback)
            $workflow['200']['inputs']['webhook_url'] = $webhookUrl;
            $workflow['200']['inputs']['custom_data'] = json_encode([
                'prompt_id' => $promptId,
                'image_id' => (string) $imageId,
                'status' => 'completed',
            ]);

            Log::info('Set webhook URL (Node 200)', [
                'url' => $webhookUrl,
                'image_id' => $imageId,
            ]);
        }
    }

    /**
     * Get the webhook URL from config or generate from app URL
     */
    public function getWebhookUrl(): string
    {
        // First try dedicated webhook URL config
        $webhookUrl = $this->config['webhook_url'] ?? null;
        if ($webhookUrl) {
            return rtrim($webhookUrl, '/');
        }

        // Fallback to app.url
        return rtrim(config('app.url'), '/').'/api/webhooks/comfyui/completion';
    }

    /**
     * Get quality megapixel value from quality preset name
     */
    public function getQualityMegapixel(string $quality): float
    {
        $presets = $this->config['quality_presets'] ?? [
            'fast' => 0.3,
            'normal' => 0.5,
            'high' => 1.0,
            'very_high' => 2.0,
        ];

        return $presets[$quality] ?? 0.3;
    }

    /**
     * Calculate initial poll delay based on quality and steps
     */
    public function calculatePollDelay(string $quality, int $steps, int $numberOfImages = 1): int
    {
        // Base generation time (in seconds)
        $baseTime = $this->config['poll_base_time'] ?? 120;
        $stepMultiplier = $this->config['poll_step_multiplier'] ?? 7;

        // Calculate base delay
        $delay = $baseTime + ($steps * $stepMultiplier);

        // Quality multipliers
        $qualityMultipliers = [
            'fast' => 1.0,
            'normal' => 2.0,
            'high' => 3.5,
            'very_high' => 7.0,
        ];

        $multiplier = $qualityMultipliers[$quality] ?? 1.0;

        $finalDelay = (int) ($delay * $multiplier);

        // Add additional time for each extra image (beyond the first)
        // Each additional image adds roughly 50-75% of the original time
        if ($numberOfImages > 1) {
            $extraImageTime = (int) ($finalDelay * 0.6 * ($numberOfImages - 1));
            $finalDelay += $extraImageTime;
        }

        // Ensure minimum delay
        // Fast quality: ~25s for 1 image, should scale with number_of_images
        return max(25, $finalDelay);
    }

    /**
     * Get the configured timeout in seconds
     */
    public function getTimeout(): int
    {
        return $this->config['timeout'] ?? 360;
    }

    /**
     * Build the head swap prompt with custom additions
     */
    public function buildHeadSwapPrompt(?string $customPrompt = null, array $additionalImageDescriptions = []): string
    {
        $basePrompt = 'head_swap: Use image 1 as the base image, preserving its environment, background, camera perspective, framing, exposure, contrast, and lighting. Remove the head from image 1 and seamlessly replace it with the head and hair from image 2 (VERY IMPORTANT).';

        // Add custom prompt if provided
        // if (! empty($customPrompt)) {
        //     $basePrompt .= " {$customPrompt}";
        // }

        // // Add additional image descriptions if provided
        // foreach ($additionalImageDescriptions as $index => $description) {
        //     $imageNumber = $index + 3;
        //     $basePrompt .= " For image {$imageNumber}: {$description}";
        // }

        $basePrompt .= "\n\nMatch the original head size, face-to-body ratio, neck thickness, shoulder alignment, and camera distance so proportions remain natural and unchanged.\n\nAdapt the inserted head to the lighting of image 1 by matching light direction, intensity, softness, color temperature, shadows, and highlights, with no independent relighting.\nPreserve the identity of image 2, including hair texture, eye color, nose structure, facial proportions, and skin details.\nMatch the pose and expression from image 1, including head tilt, rotation, eye direction, and lip position and match gaze, micro-expressions with image 1.\nEnsure seamless neck and jaw blending, consistent skin tone, realistic shadow contact, natural skin texture, and uniform sharpness.\nPhotorealistic, high quality, sharp details, 4K.";

        return $basePrompt;
    }

    /**
     * Build the generation prompt with quality suffix
     */
    public function buildGenerationPrompt(string $userPrompt): string
    {
        $suffix = 'Use the uploaded image as the sole identity reference.
                IDENTITY LOCK (must not change):
                - facial structure and proportions
                - eye shape, spacing, and color
                - nose shape and bridge
                - lip shape and mouth proportions
                - jawline and chin
                - skin tone and undertone
                - hairline, hair texture, hair color, and volume
                - head size relative to body

                Do not alter age, gender, ethnicity, or facial symmetry.
                Do not beautify or stylize the face.

                Facial expression is allowed to change according to the description below.

                Allowed changes:
                - facial expression
                - body pose
                - background and environment
                - camera framing'.$this->config['generation_prompt_suffix']
            ?? 'Lighting must adapt naturally to the new environment while preserving accurate skin color.
Photorealistic, high quality, sharp details.';

        return $userPrompt."\n".$suffix;
    }

    /**
     * Check if Flux 2 feature is enabled
     */
    public function isEnabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    /**
     * Get default steps from config
     */
    public function getDefaultSteps(): int
    {
        return (int) ($this->config['default_steps'] ?? 4);
    }

    /**
     * Get default quality from config
     */
    public function getDefaultQuality(): string
    {
        return $this->config['default_quality'] ?? 'fast';
    }

    /**
     * Get head swap LoRA filename from config
     */
    public function getHeadSwapLoRA(): string
    {
        return $this->config['head_swap_lora'] ?? 'bfs_head_v1_flux-klein_9b_step3500_rank128.safetensors';
    }

    /**
     * Get generation LoRA filename from config
     */
    public function getGenerationLoRA(): string
    {
        return $this->config['generation_lora'] ?? 'flux.2-turbo-lora.safetensors';
    }

    /**
     * Get default refine enabled setting
     */
    public function getDefaultRefineEnabled(): bool
    {
        return (bool) ($this->config['refine_enabled'] ?? false);
    }

    /**
     * Get default inpainting enabled setting
     */
    public function getDefaultInpaintingEnabled(): bool
    {
        return (bool) ($this->config['inpainting_enabled'] ?? true);
    }

    /**
     * Configure workflow for single image generation method (not head swap)
     * This ensures only the base image is used, not both images
     */
    public function configureForSingleImage(array &$workflow): void
    {
        // Node 107: Change prompt from head_swap to user prompt only
        if (isset($workflow['107'])) {
            $workflow['107']['inputs']['text'] = '{prompt}';
        }

        // Node 118: ReferenceLatent - change to use base image latent (node 150) instead of reference image (node 119)
        if (isset($workflow['118'])) {
            $workflow['118']['inputs']['latent'] = ['150', 0];
            Log::debug('Configured node 118 to use base image latent for generate method');
        }
    }

    /**
     * Set the batch size for EmptyFlux2LatentImage node
     * Node 163: EmptyFlux2LatentImage
     */
    public function setBatchSize(array &$workflow, int $batchSize): void
    {
        if (isset($workflow['163'])) {
            $workflow['163']['inputs']['batch_size'] = $batchSize;
            Log::debug('Set batch size', ['batch_size' => $batchSize]);
        }
    }

    /**
     * Get default number of images
     */
    public function getDefaultNumberOfImages(): int
    {
        return (int) ($this->config['default_number_of_images'] ?? 1);
    }

    /**
     * Get max number of images allowed
     */
    public function getMaxNumberOfImages(): int
    {
        return (int) ($this->config['max_number_of_images'] ?? 10);
    }

    /**
     * Check if webhook feature is enabled
     */
    public function isWebhookEnabled(): bool
    {
        return (bool) ($this->config['webhook_enabled'] ?? false);
    }

    /**
     * Get the poll interval for when webhooks are disabled
     */
    public function getPollInterval(): int
    {
        return (int) ($this->config['poll_interval'] ?? 1);
    }

    /**
     * Disable webhook node in the workflow (when webhooks are disabled)
     * Node 185: Notif-Webhook
     */
    public function disableWebhookNode(array &$workflow): void
    {
        if (isset($workflow['185'])) {
            // Disable the webhook node by setting mode to 'never'
            $workflow['185']['inputs']['mode'] = 'never';

            Log::info('Disabled webhook node (Node 185) in workflow');
        }
    }
}
