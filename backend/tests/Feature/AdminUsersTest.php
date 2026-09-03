<?php

namespace Tests\Feature;

use App\Models\Connection;
use App\Models\Offer;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Role;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private const DAY = '2026-06-05 12:00:00';

    private const WINDOW = 'from=2026-06-01&to=2026-06-10';

    private function adminHeaders(): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'admin', 'display_name' => 'Admin']));
        $token = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    private function makeAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin'])->id);

        return $admin;
    }

    private function tokenHeaders(User $user): array
    {
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        return ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'];
    }

    public function test_admin_can_soft_delete_a_user(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create(['email' => 'target@example.com']);

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = $this->makeAdmin();

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_admin_cannot_delete_another_admin(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();

        $this->withHeaders($this->tokenHeaders($admin))
            ->deleteJson("/api/admin/users/{$other->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('users', ['id' => $other->id, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_delete_users(): void
    {
        $customer = User::factory()->create();
        $target = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($customer))
            ->deleteJson("/api/admin/users/{$target->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    private function makeUser(string $email): User
    {
        return User::factory()->create(['created_at' => self::DAY, 'email' => $email]);
    }

    /** Create a post at a specific time (created_at isn't mass-assignable). */
    private function makePost(User $user, string $status, ?string $at = null): Post
    {
        $post = Post::create(['user_id' => $user->id, 'caption' => 'p', 'media' => [], 'status' => $status]);
        if ($at) {
            $post->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $post;
    }

    private function makeOffer(User $user, ?string $at = null): Offer
    {
        $offer = Offer::create(['user_id' => $user->id, 'name' => 'o', 'offer_url' => 'https://x.test']);
        if ($at) {
            $offer->forceFill(['created_at' => $at])->saveQuietly();
        }

        return $offer;
    }

    private function plan(): Plan
    {
        return Plan::firstOrCreate(
            ['name' => 'pro'],
            ['display_name' => 'Pro', 'price' => 10, 'currency' => 'usd', 'billing_cycle' => 'monthly']
        );
    }

    private function indexRows(array $headers, string $extra = ''): array
    {
        return $this->withHeaders($headers)
            ->getJson('/api/admin/users?' . self::WINDOW . $extra)
            ->assertOk()
            ->json('data.users');
    }

    public function test_stats_count_signups_added_cards_and_subscribed_in_range(): void
    {
        $headers = $this->adminHeaders();

        // Three users inside a known window; admin (created "now") is outside it.
        // "Added card" means card details were actually entered (card_added_at),
        // not merely that a Stripe customer exists.
        $day = '2026-06-05 12:00:00';
        User::factory()->create(['created_at' => $day]);                               // signup only
        User::factory()->create(['created_at' => $day, 'stripe_customer_id' => 'cus_B', 'card_added_at' => $day]); // added card
        $subscribed = User::factory()->create(['created_at' => $day, 'stripe_customer_id' => 'cus_C', 'card_added_at' => $day]);
        $plan = Plan::create(['name' => 'pro', 'display_name' => 'Pro', 'price' => 10, 'currency' => 'usd', 'billing_cycle' => 'monthly']);
        $subscribed->plans()->attach($plan->id, ['status' => 'active']);

        $res = $this->withHeaders($headers)
            ->getJson('/api/admin/users/stats?from=2026-06-01&to=2026-06-10')
            ->assertOk();

        $res->assertJsonPath('data.totals.signups', 3)
            ->assertJsonPath('data.totals.added_card', 2)
            ->assertJsonPath('data.totals.subscribed', 1);

        // Daily series covers every day in the window (Jun 1..10 inclusive).
        $this->assertCount(10, $res->json('data.series'));
    }

    public function test_index_lists_users_with_card_and_plan_flags(): void
    {
        $headers = $this->adminHeaders();

        User::factory()->create(['created_at' => '2026-06-05 12:00:00', 'email' => 'plain@example.com']);
        $carded = User::factory()->create(['created_at' => '2026-06-05 12:00:00', 'email' => 'carded@example.com', 'stripe_customer_id' => 'cus_X', 'card_added_at' => '2026-06-05 12:30:00']);

        $rows = $this->withHeaders($headers)
            ->getJson('/api/admin/users?from=2026-06-01&to=2026-06-10')
            ->assertOk()
            ->json('data.users');

        $this->assertSame(2, collect($rows)->count());
        $carded = collect($rows)->firstWhere('email', 'carded@example.com');
        $this->assertTrue($carded['has_card']);
        $plain = collect($rows)->firstWhere('email', 'plain@example.com');
        $this->assertFalse($plain['has_card']);
    }

    public function test_index_filters_by_card_and_sorts_by_name(): void
    {
        $headers = $this->adminHeaders();
        $day = '2026-06-05 12:00:00';
        User::factory()->create(['created_at' => $day, 'name' => 'Zoe', 'email' => 'zoe@example.com']);
        User::factory()->create(['created_at' => $day, 'name' => 'Ann', 'email' => 'ann@example.com', 'stripe_customer_id' => 'cus_A', 'card_added_at' => $day]);

        // card=yes → only the carded user.
        $carded = $this->withHeaders($headers)
            ->getJson('/api/admin/users?from=2026-06-01&to=2026-06-10&card=yes')
            ->assertOk()->json('data.users');
        $this->assertCount(1, $carded);
        $this->assertSame('ann@example.com', $carded[0]['email']);

        // sort=name asc → Ann before Zoe.
        $sorted = $this->withHeaders($headers)
            ->getJson('/api/admin/users?from=2026-06-01&to=2026-06-10&sort=name&dir=asc')
            ->assertOk()->json('data.users');
        $this->assertSame(['Ann', 'Zoe'], array_column($sorted, 'name'));
    }

    public function test_suggest_returns_matching_existing_values(): void
    {
        $headers = $this->adminHeaders();
        User::factory()->create(['email' => 'jordan@example.com']);
        User::factory()->create(['email' => 'jamie@example.com']);
        User::factory()->create(['email' => 'other@example.com']);

        $matches = $this->withHeaders($headers)
            ->getJson('/api/admin/users/suggest?field=email&q=j')
            ->assertOk()->json('data');

        $this->assertContains('jordan@example.com', $matches);
        $this->assertContains('jamie@example.com', $matches);
        $this->assertNotContains('other@example.com', $matches);
    }

    public function test_has_card_requires_actual_card_entry_not_just_a_stripe_customer(): void
    {
        $headers = $this->adminHeaders();

        // The onboarding drop-off: a Stripe customer is created when the
        // checkout session is built, BEFORE the card screen. Bailing there
        // must not count as "has card".
        User::factory()->create(['created_at' => self::DAY, 'email' => 'dropoff@example.com', 'stripe_customer_id' => 'cus_DROP']);
        User::factory()->create(['created_at' => self::DAY, 'email' => 'carded@example.com', 'stripe_customer_id' => 'cus_OK', 'card_added_at' => self::DAY]);

        $rows = collect($this->indexRows($headers));
        $this->assertFalse($rows->firstWhere('email', 'dropoff@example.com')['has_card']);
        $this->assertTrue($rows->firstWhere('email', 'carded@example.com')['has_card']);

        // card=yes filter must exclude the drop-off too.
        $yes = $this->indexRows($headers, '&card=yes');
        $this->assertSame(['carded@example.com'], array_column($yes, 'email'));

        // And the stats widget counts only real card entries.
        $stats = $this->withHeaders($headers)
            ->getJson('/api/admin/users/stats?' . self::WINDOW)
            ->assertOk()->json('data.totals');
        $this->assertSame(1, $stats['added_card']);
    }

    public function test_index_returns_engagement_counts(): void
    {
        $headers = $this->adminHeaders();
        $active = $this->makeUser('active@example.com');
        $this->makeUser('bare@example.com');

        $this->makePost($active, Post::STATUS_POSTED);
        $this->makePost($active, Post::STATUS_POSTED);
        $this->makePost($active, Post::STATUS_DRAFT);
        Connection::create(['user_id' => $active->id, 'provider' => 'youtube', 'account_name' => 'Chan', 'account_id' => 'UC1', 'access_token' => 'tok']);
        SocialAccount::create(['user_id' => $active->id, 'platform' => 'x', 'platform_account_id' => 'x-1', 'status' => 'connected']);
        SocialAccount::create(['user_id' => $active->id, 'platform' => 'linkedin', 'platform_account_id' => 'li-1', 'status' => 'needs_reauth']);
        $this->makeOffer($active);
        $this->makeOffer($active);
        $this->makeOffer($active)->delete(); // soft-deleted — excluded from the count

        $rows = collect($this->indexRows($headers));

        $row = $rows->firstWhere('email', 'active@example.com');
        $this->assertSame(3, $row['posts_count']);
        $this->assertSame(2, $row['posts_posted_count']);
        $this->assertSame(3, $row['accounts_count']);
        $this->assertSame(2, $row['offers_count']);

        $bare = $rows->firstWhere('email', 'bare@example.com');
        $this->assertSame(0, $bare['posts_count']);
        $this->assertSame(0, $bare['posts_posted_count']);
        $this->assertSame(0, $bare['accounts_count']);
        $this->assertSame(0, $bare['offers_count']);
    }

    public function test_index_returns_first_action_at(): void
    {
        $headers = $this->adminHeaders();

        // First offer predates the first post — the offer wins.
        $both = $this->makeUser('both@example.com');
        $this->makePost($both, Post::STATUS_DRAFT, '2026-06-05 14:00:00');
        $this->makeOffer($both, '2026-06-05 12:30:00');

        // A deleted offer was still an action — soft-deletes count here.
        $deleted = $this->makeUser('deleted@example.com');
        $this->makeOffer($deleted, '2026-06-05 13:00:00')->delete();

        $idle = $this->makeUser('idle@example.com');

        $rows = collect($this->indexRows($headers));

        $this->assertSame(
            Carbon::parse('2026-06-05 12:30:00')->toISOString(),
            $rows->firstWhere('email', 'both@example.com')['first_action_at']
        );
        $this->assertSame(
            Carbon::parse('2026-06-05 13:00:00')->toISOString(),
            $rows->firstWhere('email', 'deleted@example.com')['first_action_at']
        );
        $this->assertNull($rows->firstWhere('email', 'idle@example.com')['first_action_at']);
    }

    public function test_index_cancellation_uses_latest_plan_row(): void
    {
        $headers = $this->adminHeaders();
        $plan = $this->plan();

        // Cancelled once, then re-subscribed: the newer row wins → not cancelled.
        $resubbed = $this->makeUser('resubbed@example.com');
        $resubbed->plans()->attach($plan->id, ['status' => 'inactive', 'cancelled_at' => '2026-06-06 10:00:00']);
        $resubbed->plans()->attach($plan->id, ['status' => 'active', 'cancelled_at' => null]);

        // Graceful cancel: cancelled_at set while the sub is still active.
        $cancelled = $this->makeUser('cancelled@example.com');
        $cancelled->plans()->attach($plan->id, ['status' => 'active', 'cancelled_at' => '2026-06-07 09:00:00']);

        $never = $this->makeUser('never@example.com');

        $rows = collect($this->indexRows($headers));

        $this->assertNull($rows->firstWhere('email', 'resubbed@example.com')['subscription_cancelled_at']);
        $this->assertSame(
            Carbon::parse('2026-06-07 09:00:00')->toISOString(),
            $rows->firstWhere('email', 'cancelled@example.com')['subscription_cancelled_at']
        );
        $this->assertNull($rows->firstWhere('email', 'never@example.com')['subscription_cancelled_at']);
    }

    public function test_index_sorts_by_engagement_counts(): void
    {
        $headers = $this->adminHeaders();
        $none = $this->makeUser('none@example.com');
        $one = $this->makeUser('one@example.com');
        $three = $this->makeUser('three@example.com');
        $this->makePost($one, Post::STATUS_DRAFT);
        foreach (range(1, 3) as $i) {
            $this->makePost($three, Post::STATUS_POSTED);
        }
        $this->makeOffer($one);
        $this->makeOffer($one);
        $this->makeOffer($three);

        $byPosts = $this->indexRows($headers, '&sort=posts_count&dir=desc');
        $this->assertSame(
            ['three@example.com', 'one@example.com', 'none@example.com'],
            array_column($byPosts, 'email')
        );

        $byOffers = $this->indexRows($headers, '&sort=offers_count&dir=desc');
        $this->assertSame(['one@example.com', 'three@example.com', 'none@example.com'], array_column($byOffers, 'email'));
    }

    public function test_index_sorts_by_first_action_at_nulls_last(): void
    {
        $headers = $this->adminHeaders();
        $late = $this->makeUser('late@example.com');
        $early = $this->makeUser('early@example.com');
        $idle = $this->makeUser('idle@example.com');
        $this->makePost($late, Post::STATUS_DRAFT, '2026-06-06 12:00:00');
        $this->makeOffer($early, '2026-06-05 13:00:00');

        $asc = $this->indexRows($headers, '&sort=first_action_at&dir=asc');
        $this->assertSame(['early@example.com', 'late@example.com', 'idle@example.com'], array_column($asc, 'email'));

        // No-action users stay last even when descending.
        $desc = $this->indexRows($headers, '&sort=first_action_at&dir=desc');
        $this->assertSame(['late@example.com', 'early@example.com', 'idle@example.com'], array_column($desc, 'email'));
    }

    public function test_index_filters_by_cancelled(): void
    {
        $headers = $this->adminHeaders();
        $plan = $this->plan();
        $cancelled = $this->makeUser('cancelled@example.com');
        $cancelled->plans()->attach($plan->id, ['status' => 'active', 'cancelled_at' => '2026-06-07 09:00:00']);
        $this->makeUser('never@example.com');

        $yes = $this->indexRows($headers, '&cancelled=yes');
        $this->assertSame(['cancelled@example.com'], array_column($yes, 'email'));

        $no = $this->indexRows($headers, '&cancelled=no');
        $this->assertSame(['never@example.com'], array_column($no, 'email'));

        // Both values selected = no-op filter.
        $this->assertCount(2, $this->indexRows($headers, '&cancelled=yes,no'));
    }

    public function test_non_admin_is_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])->json('data.token');

        $this->withHeaders(['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'])
            ->getJson('/api/admin/users/stats')
            ->assertForbidden();
    }

    public function test_admin_can_list_a_users_connected_accounts_across_both_stores(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();
        SocialAccount::create([
            'user_id' => $target->id, 'platform' => 'tiktok', 'platform_account_id' => 'tt1',
            'name' => 'TT Account', 'username' => 'ttuser', 'avatar_url' => 'https://example.com/a.jpg',
            'access_token' => 'secret-token', 'status' => 'active',
        ]);
        Connection::create([
            'user_id' => $target->id, 'provider' => 'youtube', 'account_name' => 'YT Channel',
            'account_id' => 'UC1', 'access_token' => 'secret-token',
        ]);

        $data = $this->withHeaders($this->tokenHeaders($admin))
            ->getJson("/api/admin/users/{$target->id}/accounts")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $data);
        $platforms = array_column($data, 'platform');
        $this->assertContains('tiktok', $platforms);
        $this->assertContains('youtube', $platforms);
        $tiktok = collect($data)->firstWhere('platform', 'tiktok');
        $this->assertSame('ttuser', $tiktok['username']);
        $this->assertSame('active', $tiktok['status']);
        // Every account carries a clickable URL: stored profile_url when present,
        // else built from platform + username / account id.
        $this->assertSame('https://www.tiktok.com/@ttuser', $tiktok['url']);
        $youtube = collect($data)->firstWhere('platform', 'youtube');
        $this->assertSame('https://www.youtube.com/channel/UC1', $youtube['url']);
        // Tokens must never reach the admin UI.
        $this->assertStringNotContainsString('secret-token', json_encode($data));
    }

    public function test_account_url_prefers_stored_profile_url(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();
        SocialAccount::create([
            'user_id' => $target->id, 'platform' => 'linkedin', 'platform_account_id' => 'li1',
            'name' => 'LI Page', 'profile_url' => 'https://www.linkedin.com/in/someone/',
        ]);

        $data = $this->withHeaders($this->tokenHeaders($admin))
            ->getJson("/api/admin/users/{$target->id}/accounts")
            ->assertOk()
            ->json('data');

        $this->assertSame('https://www.linkedin.com/in/someone/', $data[0]['url']);
    }

    public function test_admin_show_returns_user_roles_and_available_roles(): void
    {
        $admin = $this->makeAdmin();
        $customerRole = Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        $target = User::factory()->create();
        $target->roles()->attach($customerRole->id);

        $data = $this->withHeaders($this->tokenHeaders($admin))
            ->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame($target->email, $data['user']['email']);
        $this->assertSame(['customer'], $data['user']['roles']);
        $this->assertContains('admin', array_column($data['roles'], 'name'));
        $this->assertContains('customer', array_column($data['roles'], 'name'));
    }

    public function test_admin_can_change_a_users_role(): void
    {
        $admin = $this->makeAdmin();
        $customerRole = Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        $target = User::factory()->create();
        $target->roles()->attach($customerRole->id);

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['admin']);

        $this->assertTrue($target->fresh()->isAdmin());
        $this->assertCount(1, $target->fresh()->roles); // replaced, not stacked
    }

    public function test_admin_cannot_change_their_own_role(): void
    {
        $admin = $this->makeAdmin();

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$admin->id}/role", ['role' => 'customer'])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_role_change_requires_an_existing_role(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'superhero'])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_use_user_management_endpoints(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();
        $headers = $this->tokenHeaders($user);

        $this->withHeaders($headers)->getJson("/api/admin/users/{$target->id}/accounts")->assertForbidden();
        $this->withHeaders($headers)->getJson("/api/admin/users/{$target->id}")->assertForbidden();
        $this->withHeaders($headers)->putJson("/api/admin/users/{$target->id}/role", ['role' => 'admin'])->assertForbidden();
    }
}
