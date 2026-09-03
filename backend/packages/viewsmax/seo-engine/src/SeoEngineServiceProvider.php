<?php

namespace ViewsMax\SeoEngine;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use ViewsMax\SeoEngine\Console\DiscoverKeywords;
use ViewsMax\SeoEngine\Console\DraftArticles;
use ViewsMax\SeoEngine\Console\ProspectBacklinks;
use ViewsMax\SeoEngine\Console\PublishArticles;
use ViewsMax\SeoEngine\Console\RunPipeline;
use ViewsMax\SeoEngine\Contracts\ArticlePublisher;
use ViewsMax\SeoEngine\Contracts\ArticleWriter;
use ViewsMax\SeoEngine\Contracts\BacklinkSource;
use ViewsMax\SeoEngine\Contracts\ImageWriter;
use ViewsMax\SeoEngine\Contracts\KeywordSource;
use ViewsMax\SeoEngine\Publishers\WordPressPublisher;
use ViewsMax\SeoEngine\Sources\DataForSeo;
use ViewsMax\SeoEngine\Writers\AnthropicArticleWriter;
use ViewsMax\SeoEngine\Writers\OpenAiImageWriter;

class SeoEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/seo-engine.php', 'seo-engine');

        // Provider-agnostic contracts: swap any vendor by rebinding here (or in
        // the host app) without touching the pipeline.
        $this->app->bind(KeywordSource::class, DataForSeo::class);
        $this->app->bind(BacklinkSource::class, DataForSeo::class);
        $this->app->bind(ArticleWriter::class, AnthropicArticleWriter::class);
        $this->app->bind(ArticlePublisher::class, WordPressPublisher::class);
        $this->app->bind(ImageWriter::class, OpenAiImageWriter::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');

        $this->publishes([
            __DIR__.'/../config/seo-engine.php' => config_path('seo-engine.php'),
        ], 'seo-engine-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                DiscoverKeywords::class,
                DraftArticles::class,
                PublishArticles::class,
                ProspectBacklinks::class,
                RunPipeline::class,
            ]);
        }

        // Daily heartbeat — gated on the kill-switch so a disabled engine
        // schedules nothing at all.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            if (config('seo-engine.enabled')) {
                $schedule->command('seo:run')->dailyAt('06:30')->withoutOverlapping();
            }
        });
    }
}
