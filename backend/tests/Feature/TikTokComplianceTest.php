<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * TikTok API-client audit compliance enforced server-side in PostController:
 * privacy must be explicitly chosen (no default), branded content cannot be
 * private, and disclosing commercial content requires at least one brand
 * option. A direct API caller is held to the same rules as the composer.
 */
class TikTokComplianceTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->token = User::factory()->create()->createToken('t')->plainTextToken;
    }

    private function publishTikTok(array $options)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])
            ->postJson('/api/posts', [
                'caption' => 'Hi',
                'platforms' => ['tiktok'],
                'media' => [['type' => 'image', 'url' => 'https://cdn.example/1.jpg']],
                'status' => 'posted',
                'options' => ['tiktok' => $options],
            ]);
    }

    public function test_publishing_requires_an_explicitly_chosen_privacy_level(): void
    {
        $res = $this->publishTikTok([]); // no privacy_level
        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('who can view', $res->json('message'));
    }

    public function test_empty_string_privacy_is_rejected(): void
    {
        $this->publishTikTok(['privacy_level' => ''])->assertStatus(422);
    }

    public function test_branded_content_cannot_be_private(): void
    {
        $res = $this->publishTikTok([
            'privacy_level' => 'SELF_ONLY',
            'disclose_commercial' => true,
            'branded_content' => true,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('private', $res->json('message'));
    }

    public function test_commercial_disclosure_requires_at_least_one_brand_option(): void
    {
        $res = $this->publishTikTok([
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'disclose_commercial' => true,
        ]);
        $res->assertStatus(422);
    }

    public function test_valid_tiktok_options_pass_validation(): void
    {
        $this->publishTikTok([
            'privacy_level' => 'PUBLIC_TO_EVERYONE',
            'disclose_commercial' => true,
            'your_brand' => true,
        ])->assertStatus(201);
    }

    public function test_drafts_are_not_gated_by_the_compliance_rules(): void
    {
        // Drafts can be saved incomplete; the rules only bite on publish/schedule.
        $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'])
            ->postJson('/api/posts', [
                'caption' => 'Draft',
                'platforms' => ['tiktok'],
                'media' => [['type' => 'image', 'url' => 'https://cdn.example/1.jpg']],
                'status' => 'draft',
                'options' => ['tiktok' => []],
            ])->assertStatus(201);
    }
}
