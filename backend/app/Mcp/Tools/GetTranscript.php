<?php

namespace App\Mcp\Tools;

use App\Services\CaptApiService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * The free transcript tool (/free-tools/transcript) over MCP: same service,
 * DB cache, output and per-minute limit as the web app.
 */
#[Title('Get a video transcript')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
class GetTranscript extends ViewsMaxTool
{
    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'get_transcript';
    }

    public function description(): string
    {
        return 'Get the transcript (the spoken words) of a public YouTube, TikTok, or Instagram video '
            . 'from its URL. Returns the full `text`, timed `segments` ({text, startMs, endMs}) you can '
            . 'use to show timestamps or build an SRT file, and the detected `language`. Use when the '
            . 'user wants what a video says: to read, quote, summarize, or repurpose it.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('platform')->description('youtube, tiktok, or instagram.')->required()
            ->string('url')->description('Public URL of the video on that platform.')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->transcriptLimit()) {
            return $limited;
        }

        $validated = Validator::validate($arguments, [
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'url' => 'required|url|max:2048',
        ]);

        try {
            $result = app(CaptApiService::class)->getTranscript($validated['platform'], $validated['url']);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        $t = $result['transcript'];

        return ToolResult::json([
            'platform' => $t->platform,
            'url' => $t->source_url,
            'text' => $t->text,
            'segments' => $t->segments,
            'language' => $t->language,
            'cached' => $result['cached'],
        ]);
    }

    /**
     * The web route's `transcript` limiter (AppServiceProvider), applied here
     * because /mcp doesn't pass through that route's throttle middleware.
     */
    private function transcriptLimit(): ?ToolResult
    {
        $limit = RateLimiter::limiter('transcript')(request());
        $bucket = 'mcp-tool:' . $limit->key;

        if (RateLimiter::tooManyAttempts($bucket, $limit->maxAttempts)) {
            return ToolResult::error(
                "Rate limit exceeded: at most {$limit->maxAttempts} {$this->name()} calls per minute. "
                . 'Try again in ' . RateLimiter::availableIn($bucket) . ' seconds.'
            );
        }

        RateLimiter::hit($bucket, $limit->decaySeconds);

        return null;
    }
}
