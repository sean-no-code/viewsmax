<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Instagram tokens can be revoked before their recorded expiry (password
 * change, app de-authorized, invalidated session), surfacing as a Graph
 * OAuthException code 190. When that happens on publish we must flag the
 * account for reconnection instead of leaving it "connected" with a dead token.
 */
class InstagramReauthTest extends TestCase
{
    use RefreshDatabase;

    private function connectedAccount(User $user): SocialAccount
    {
        return $user->socialAccounts()->create([
            'platform' => 'instagram',
            'platform_account_id' => 'ig-1',
            'name' => 'Tester',
            'access_token' => 'dead-token',
            // Still "valid" by time — the time-based check can't catch this.
            'token_expires_at' => now()->addDays(30),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function imagePost(): SocialPost
    {
        return new SocialPost([
            'content' => 'hello',
            'media' => [['type' => 'image', 'url' => 'https://cdn.example/a.jpg']],
        ]);
    }

    public function test_expired_session_flags_the_account_for_reconnect(): void
    {
        Http::fake([
            '*/media' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has expired',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        $user = User::factory()->create();
        $account = $this->connectedAccount($user);

        $result = (new InstagramProvider)->publish($account, $this->imagePost());

        $this->assertFalse($result->success);
        $this->assertStringContainsStringIgnoringCase('reconnect', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->fresh()->status);
    }

    /** A non-auth failure must NOT flag the account for reconnect. */
    public function test_other_errors_do_not_flag_reconnect(): void
    {
        Http::fake([
            '*/media' => Http::response([
                'error' => ['message' => 'Media URL unreachable', 'type' => 'IGApiException', 'code' => 9004],
            ], 400),
        ]);

        $user = User::factory()->create();
        $account = $this->connectedAccount($user);

        $result = (new InstagramProvider)->publish($account, $this->imagePost());

        $this->assertFalse($result->success);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->fresh()->status);
    }
}
