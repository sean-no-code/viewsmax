<?php

namespace Tests\Feature;

use App\Services\AnthropicService;
use App\Services\ThumbnailAnalyzer;
use App\Services\TitleAnalyzer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Throwable;

/**
 * The Anthropic model is a server setting (services.anthropic.model), never
 * something a caller can choose: a request carrying `model` must not change
 * what is sent to the API. Each service reads it once, from config, in its
 * constructor.
 */
class AnthropicModelIsFixedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // 'staging': AnthropicService returns canned text in local/testing, which never reaches the API.
        config(['app.env' => 'staging', 'services.anthropic.model' => 'claude-configured', 'services.anthropic.api_key' => 'sk-test']);
        request()->merge(['model' => 'claude-opus-attacker', 'ai_model' => 'claude-opus-attacker']);
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => '{}']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]])]);
    }

    private function assertOnlyConfiguredModelSent(callable $call): void
    {
        try {
            $call();
        } catch (Throwable) {
            // Parsing the stub reply may fail; only the outgoing request matters here.
        }

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'));
        Http::assertNotSent(fn (Request $r) => ($r['model'] ?? null) !== 'claude-configured');
    }

    public function test_script_generation_uses_the_configured_model(): void
    {
        $this->assertOnlyConfiguredModelSent(fn () => app(AnthropicService::class)->generateScript('Project brief'));
    }

    public function test_title_analysis_uses_the_configured_model(): void
    {
        $this->assertOnlyConfiguredModelSent(fn () => app(TitleAnalyzer::class)->analyzeTitle('A title'));
    }

    public function test_thumbnail_analysis_uses_the_configured_model(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('thumbnails/t.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        $this->assertOnlyConfiguredModelSent(fn () => app(ThumbnailAnalyzer::class)->analyzeThumbnail('thumbnails/t.png', 'A title'));
    }
}
