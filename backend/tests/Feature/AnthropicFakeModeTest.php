<?php

namespace Tests\Feature;

use App\Services\AnthropicService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnthropicFakeModeTest extends TestCase
{
    private const PAYLOAD = [
        'idea' => ['topic' => 'Real topic', 'idea_seed' => 'seed', 'unique_angle' => 'angle'],
        'hook' => [['time' => '0.0s', 'line' => 'Real hook', 'note' => 'note']],
        'structure' => ['summary' => 'real', 'beats' => []],
        'visual' => [],
        'transcript' => [['time' => '0:00', 'text' => 'Real line', 'label' => 'hook']],
    ];

    public function test_local_and_development_envs_return_canned_breakdowns(): void
    {
        Http::fake(); // any real call would be a bug

        foreach (['local', 'development', 'testing'] as $env) {
            config()->set('app.env', $env);
            $payload = app(AnthropicService::class)->generateOutlierBreakdown(['title' => 'T'], 'transcript text');
            $this->assertNotSame('Real topic', $payload['idea']['topic'] ?? null, "env {$env} should be canned");
        }

        Http::assertNothingSent();
    }

    public function test_other_envs_call_the_real_api(): void
    {
        config()->set('app.env', 'staging');
        config()->set('services.anthropic.api_key', 'sk-test');

        Http::fake(['*' => Http::response([
            'content' => [['text' => json_encode(self::PAYLOAD)]],
        ], 200)]);

        $payload = app(AnthropicService::class)->generateOutlierBreakdown(['title' => 'T'], 'transcript text');

        $this->assertSame('Real topic', $payload['idea']['topic']);
        Http::assertSentCount(1);
    }
}
