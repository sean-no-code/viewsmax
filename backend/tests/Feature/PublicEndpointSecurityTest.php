<?php

namespace Tests\Feature;

use App\Http\Controllers\ComfyUIWebhookController;
use App\Jobs\ProcessComfyUIWebhookJob;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Routes reachable without a signed-in user must either be deliberately
 * public (login, OAuth discovery, tracking pixel) or authenticate the caller
 * some other way (a webhook signature). These close the gaps found in the
 * 2026-10-11 audit.
 */
class PublicEndpointSecurityTest extends TestCase
{
    public function test_the_public_thumbnail_test_endpoint_is_gone(): void
    {
        // It ran paid OpenAI image generation for anyone who posted to it.
        $this->postJson('/api/test/thumbnails', ['description' => 'x'])->assertNotFound();
    }

    public function test_the_unused_oauth_device_flow_is_not_exposed(): void
    {
        // MCP clients use authorization code + PKCE only. The device flow's
        // approval screen sat outside the consent-screen access gate.
        $this->get('/oauth/device')->assertNotFound();
        $this->postJson('/oauth/device/code', ['client_id' => 'x'])->assertNotFound();
    }

    public function test_production_never_skips_the_stripe_signature_check(): void
    {
        config(['services.stripe.signature_check' => false, 'services.stripe.webhook_secret' => 'whsec_test']);
        $this->app['env'] = 'production';

        $this->postJson('/api/webhooks/stripe', [
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_forged']],
        ])->assertStatus(400);
    }

    public function test_comfyui_webhook_rejects_an_unsigned_call(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/comfyui/completion', ['image_id' => '7', 'status' => 'failed'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_comfyui_webhook_rejects_a_signature_for_another_image(): void
    {
        Queue::fake();
        $url = ComfyUIWebhookController::signedUrl('http://localhost/api/webhooks/comfyui/completion', 7);

        $this->postJson(str_replace('image_id=7', 'image_id=8', $url), ['image_id' => '8', 'status' => 'failed'])
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_comfyui_webhook_accepts_its_signed_url_and_trusts_only_the_signed_image(): void
    {
        Queue::fake();
        $url = ComfyUIWebhookController::signedUrl('http://localhost/api/webhooks/comfyui/completion', 7);

        // A body naming a different image can't redirect the update.
        $this->postJson($url, ['prompt_id' => 'p-1', 'image_id' => '99', 'status' => 'completed'])->assertOk();

        Queue::assertPushed(ProcessComfyUIWebhookJob::class, fn ($job) => $job->imageId === '7');
    }
}
