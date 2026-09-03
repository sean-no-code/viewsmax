<?php

namespace Tests\Feature;

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\TikTokPublishService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Content Sharing Guidelines, Required UX 1(b):
 *
 *   "When the creator_info API returns that the creator can not make more posts
 *    at this moment, API Clients must stop the current publishing attempt and
 *    prompt users to try again later."
 *
 * The trap is how TikTok signals it. Their Query Creator Info reference lists
 * three codes under an HTTP status it labels "200 (intentional)":
 * spam_risk_too_many_posts, spam_risk_user_banned_from_posting and
 * reached_active_user_cap. Judging the call by HTTP status alone — as this did —
 * lets all three through as success, and the creator is never told to retry.
 *
 * Their docs are explicit: "any code other than `ok` indicates the request did
 * not succeed."
 */
class TikTokCreatorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function account(User $user): SocialAccount
    {
        return SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'tiktok',
            'platform_account_id' => 'tt-1',
            'name' => 'Tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    /** creator_info answering HTTP 200 with a non-ok error code, as TikTok does. */
    private function fakeCreatorInfo(string $code, int $status = 200): void
    {
        Http::fake([
            '*/post/publish/creator_info/query/' => Http::response([
                'data' => [],
                'error' => ['code' => $code, 'message' => 'nope', 'log_id' => 'x'],
            ], $status),
        ]);
    }

    public static function cannotPostCodes(): array
    {
        return [
            'daily cap reached' => ['spam_risk_too_many_posts'],
            'banned from posting' => ['spam_risk_user_banned_from_posting'],
            'client active-user cap' => ['reached_active_user_cap'],
        ];
    }

    /**
     * @dataProvider cannotPostCodes
     */
    public function test_creator_cannot_post_is_detected_despite_http_200(string $code): void
    {
        $this->fakeCreatorInfo($code);

        $user = User::factory()->create();
        $account = $this->account($user);

        try {
            app(TikTokPublishService::class)->creatorInfo($account);
            $this->fail("Expected {$code} to stop the publishing attempt, but it was treated as success.");
        } catch (\Throwable $e) {
            $this->assertStringContainsString('try again later', strtolower($e->getMessage()),
                'Requirement 1b asks for the user to be prompted to try again later.');
        }
    }

    public function test_any_other_non_ok_code_is_also_treated_as_a_failure(): void
    {
        $this->fakeCreatorInfo('something_unexpected');

        $user = User::factory()->create();
        $account = $this->account($user);

        $this->expectException(\Throwable::class);
        app(TikTokPublishService::class)->creatorInfo($account);
    }

    public function test_ok_still_returns_the_creator_details(): void
    {
        Http::fake([
            '*/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'creator_nickname' => 'Tester',
                    'privacy_level_options' => ['SELF_ONLY'],
                    'duet_disabled' => true,
                    'max_video_post_duration_sec' => 60,
                ],
                'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'x'],
            ], 200),
        ]);

        $user = User::factory()->create();
        $info = app(TikTokPublishService::class)->creatorInfo($this->account($user));

        $this->assertSame('Tester', $info['creator_nickname']);
        $this->assertSame(['SELF_ONLY'], $info['privacy_level_options']);
        $this->assertTrue($info['duet_disabled']);
    }

    /**
     * The composer has to be able to tell "creator can't post right now" apart
     * from a generic outage, so it can show the retry-later prompt rather than a
     * blank options panel.
     */
    public function test_endpoint_reports_creator_unavailable_distinctly(): void
    {
        $this->fakeCreatorInfo('spam_risk_too_many_posts');

        $user = User::factory()->create();
        $this->account($user);

        $response = $this->getJson('/api/connections/tiktok/creator-info', [
            'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
        ]);

        $response->assertStatus(422)->assertJsonPath('code', 'creator_cannot_post');
        $this->assertStringContainsString('try again later', strtolower($response->json('message')));
    }
}
