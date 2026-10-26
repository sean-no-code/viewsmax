<?php

namespace Tests\Feature;

use App\Services\AnthropicService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AnthropicService returns canned text in local/development/testing (no API
 * cost, the suite never reaches the network) and calls Claude everywhere else.
 */
class AnthropicFakeModeTest extends TestCase
{
    public function test_local_and_development_envs_return_canned_scripts(): void
    {
        Http::fake(); // any real call would be a bug

        foreach (['local', 'development', 'testing'] as $env) {
            config()->set('app.env', $env);
            $script = app(AnthropicService::class)->generateScript('A video about sushi');
            $this->assertNotEmpty($script, "env {$env} should return a canned script");
        }

        Http::assertNothingSent();
    }

    public function test_other_envs_call_the_real_api(): void
    {
        config()->set('app.env', 'staging');
        config()->set('services.anthropic.api_key', 'sk-test');

        Http::fake(['*' => Http::response([
            'content' => [['text' => "Real Title\n\nReal script body about sushi."]],
        ], 200)]);

        app(AnthropicService::class)->generateScript('A video about sushi');

        Http::assertSentCount(1);
    }
}
