<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\SocialProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * LinkedIn rejects any LinkedIn-Version header that isn't an active YYYYMM
 * with 426 NONEXISTENT_VERSION (prod once shipped a malformed 8-digit
 * "20250601"). The provider must always send a 6-digit version, treating
 * malformed config as absent.
 */
class LinkedInVersionTest extends TestCase
{
    use RefreshDatabase;

    private function connectLinkedIn(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'linkedin',
            'platform_account_id' => 'li-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
            'metadata' => ['author_urn' => 'urn:li:person:me1'],
        ]);
    }

    private function publishAndCaptureVersion(): ?string
    {
        $version = null;
        Http::fake([
            'api.linkedin.com/rest/posts' => function ($request) use (&$version) {
                $version = $request->header('LinkedIn-Version')[0] ?? null;

                return Http::response(['id' => 'urn:li:share:1'], 201);
            },
        ]);

        $user = User::factory()->create();
        $account = $this->connectLinkedIn($user);
        app(SocialProviderManager::class)->for('linkedin')->publish($account, new SocialPost([
            'content' => 'hello',
            'media' => [],
        ]));

        return $version;
    }

    public function test_malformed_configured_version_falls_back_to_a_valid_default(): void
    {
        config(['social.platforms.linkedin.api_version' => '20250601']);

        $version = $this->publishAndCaptureVersion();

        $this->assertMatchesRegularExpression('/^\d{6}$/', (string) $version);
        $this->assertNotSame('20250601', $version);
    }

    public function test_valid_configured_version_is_sent_verbatim(): void
    {
        config(['social.platforms.linkedin.api_version' => '202601']);

        $this->assertSame('202601', $this->publishAndCaptureVersion());
    }
}
