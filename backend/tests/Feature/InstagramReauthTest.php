<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Social\MetaGraphErrors;
use App\Services\Social\Providers\InstagramProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Instagram tokens can be revoked before their recorded expiry (password
 * change, app de-authorized, invalidated session), surfacing as a Graph
 * OAuthException code 190. When that happens on publish we must flag the
 * account for reconnection instead of leaving it "connected" with a dead token.
 *
 * The reverse matters just as much: Meta sends MOST publish rejections as
 * type OAuthException too (caption too long, 25 posts a day, bad media), and
 * flagging those used to brick a healthy account on every ordinary failure.
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

    /** @param  array<string, mixed>  $error */
    private function publishWithGraphError(array $error, int $status = 400): array
    {
        Http::fake(['*/media' => Http::response(['error' => $error], $status)]);

        $user = User::factory()->create();
        $account = $this->connectedAccount($user);

        $result = (new InstagramProvider)->publish($account, $this->imagePost());
        $this->assertFalse($result->success);

        return [$result, $account->fresh()];
    }

    public function test_expired_session_flags_the_account_for_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError([
            'message' => 'Error validating access token: Session has expired',
            'type' => 'OAuthException',
            'code' => 190,
        ]);

        $this->assertSame(MetaGraphErrors::MESSAGE_REAUTH, $result->error);
        $this->assertStringContainsStringIgnoringCase('reconnect', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertSame($result->error, $account->last_error);
    }

    /** A checkpoint is a 190 too, but a plain reconnect loops: the user must log in on Instagram first. */
    public function test_checkpoint_flags_reconnect_with_the_log_in_first_message(): void
    {
        [$result, $account] = $this->publishWithGraphError([
            'message' => 'You cannot access the app till you log in to www.instagram.com and follow the instructions given.',
            'type' => 'OAuthException',
            'code' => 190,
            'error_subcode' => 2207051,
        ]);

        $this->assertSame(MetaGraphErrors::MESSAGE_CHECKPOINT, $result->error);
        $this->assertStringContainsString('instagram.com', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
        $this->assertSame($result->error, $account->last_error);
    }

    public function test_session_key_malformed_is_a_checkpoint(): void
    {
        [$result, $account] = $this->publishWithGraphError(['message' => 'Session key is malformed', 'type' => 'OAuthException', 'code' => 190]);

        $this->assertSame(MetaGraphErrors::MESSAGE_CHECKPOINT, $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
    }

    public function test_non_business_account_flags_reconnect_with_the_convert_message(): void
    {
        [$result, $account] = $this->publishWithGraphError(['message' => 'The user is not an Instagram Business', 'type' => 'OAuthException', 'code' => 100]);

        $this->assertStringContainsString('convert it to a business account', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_NEEDS_REAUTH, $account->status);
    }

    public function test_caption_too_long_oauth_exception_does_not_flag_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError([
            'message' => 'Invalid parameter',
            'type' => 'OAuthException',
            'code' => 100,
            'error_subcode' => 2207010,
            'error_user_title' => 'Caption too long',
        ]);

        $this->assertSame('Caption is too long', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
        $this->assertNull($account->last_error);
    }

    public function test_daily_limit_oauth_exception_does_not_flag_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError([
            'message' => 'Application request limit reached',
            'type' => 'OAuthException',
            'code' => 9,
            'error_subcode' => 2207042,
        ]);

        $this->assertSame('You have reached the maximum of 25 posts per day, allowed for your account', $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
    }

    public function test_page_request_limit_fails_without_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError(['message' => '(#32) Page request limit reached', 'type' => 'OAuthException', 'code' => 32], 403);

        $this->assertStringContainsString('try again tomorrow', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
    }

    public function test_missing_permissions_fail_with_the_permissions_message_not_a_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError([
            'message' => '(#200) The user hasn\'t authorized the application to perform this action',
            'type' => 'OAuthException',
            'code' => 200,
        ]);

        $this->assertSame(MetaGraphErrors::MESSAGE_PERMISSIONS, $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
    }

    /** A non-auth failure must NOT flag the account for reconnect, and the user sees Meta's reason, not JSON. */
    public function test_other_errors_do_not_flag_reconnect(): void
    {
        [$result, $account] = $this->publishWithGraphError(['message' => 'Media URL unreachable', 'type' => 'IGApiException', 'code' => 9004]);

        $this->assertSame('Meta rejected the post: Media URL unreachable', $result->error);
        $this->assertStringNotContainsString('{', (string) $result->error);
        $this->assertSame(SocialAccount::STATUS_CONNECTED, $account->status);
    }
}
