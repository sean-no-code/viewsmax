<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * User events only record what a signed-in person does in the web app; the
 * model enforces it, so every event type gets the rule for free. The HTTP
 * paths (web app, MCP, API key) are covered in OutlierBreakdownViewEventTest.
 */
class UserEventTest extends TestCase
{
    use RefreshDatabase;

    /** Act as $user on the current request, carrying a token with these abilities. */
    private function signInWith(User $user, array $abilities): void
    {
        $token = $user->createToken('test', $abilities)->accessToken;
        $user->withAccessToken(PersonalAccessToken::findOrFail($token->id));
        request()->setUserResolver(fn () => $user);
    }

    public function test_records_for_the_web_app_user(): void
    {
        $user = User::factory()->create();
        $this->signInWith($user, ['*']);

        $event = UserEvent::record($user, UserEvent::OUTLIER_BREAKDOWN_VIEWED, ['video_id' => 'abc']);

        $this->assertNotNull($event);
        $this->assertSame(1, UserEvent::count());
    }

    public function test_skips_requests_without_a_signed_in_user(): void
    {
        $this->assertNull(UserEvent::record(User::factory()->create(), UserEvent::OUTLIER_BREAKDOWN_VIEWED));
        $this->assertSame(0, UserEvent::count());
    }

    public function test_skips_api_keys(): void
    {
        $user = User::factory()->create();
        $this->signInWith($user, ['mcp:read', 'mcp:write']);

        $this->assertNull(UserEvent::record($user, UserEvent::OUTLIER_BREAKDOWN_VIEWED));
        $this->assertSame(0, UserEvent::count());
    }

    public function test_skips_a_signed_in_user_without_a_token(): void
    {
        // MCP: the user is resolved from an OAuth token or key, not a login token.
        $user = User::factory()->create();
        request()->setUserResolver(fn () => $user);

        $this->assertNull(UserEvent::record($user, UserEvent::OUTLIER_BREAKDOWN_VIEWED));
        $this->assertSame(0, UserEvent::count());
    }

    public function test_skips_events_for_someone_other_than_the_signed_in_user(): void
    {
        $this->signInWith(User::factory()->create(), ['*']);

        $this->assertNull(UserEvent::record(User::factory()->create(), UserEvent::OUTLIER_BREAKDOWN_VIEWED));
        $this->assertSame(0, UserEvent::count());
    }

    public function test_the_rule_also_holds_for_direct_creates(): void
    {
        $user = User::factory()->create();

        UserEvent::create(['user_id' => $user->id, 'event_name' => 'x', 'created_at' => now()]);

        $this->assertSame(0, UserEvent::count());
    }
}
