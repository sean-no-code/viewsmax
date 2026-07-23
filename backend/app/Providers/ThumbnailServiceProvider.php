<?php

namespace App\Providers;

use App\Services\Contracts\ThumbnailServiceInterface;
use App\Services\GeminiService;
use App\Services\OpenAIService;
use App\Services\ReplicateThumbnailService;
use App\Services\PromptService;
use Illuminate\Support\ServiceProvider;

class ThumbnailServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register the default thumbnail service (for thumbnails WITHOUT trained models)
        $this->app->bind(ThumbnailServiceInterface::class, function ($app) {
            // You can change this to switch between services
            $service = config('services.thumbnail.default_service', 'openai');
            $promptService = $app->make(PromptService::class);
            
            return match ($service) {
                'gemini' => new GeminiService($promptService),
                'openai' => new OpenAIService($promptService),
                'replicate' => new ReplicateThumbnailService($promptService),
                'flux' => $app->make(\App\Services\ComfyUIService::class),
                'flux2' => $app->make(\App\Services\ComfyUIService::class),
                default => new OpenAIService($promptService),
            };
        });

        // Register ReplicateThumbnailService as a singleton for trained model generation
        $this->app->singleton(ReplicateThumbnailService::class, function ($app) {
            $promptService = $app->make(PromptService::class);
            return new ReplicateThumbnailService($promptService);
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}

