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
use Illuminate\Support\Facades\Log;
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

    // --- Create user + promotional access ------------------------------------

    private function promoRole(): Role
    {
        return Role::firstOrCreate(['name' => 'promotional_customer'], ['display_name' => 'Promotional customer']);
    }

    public function test_admin_can_create_a_promotional_customer_with_a_free_window(): void
    {
        $admin = $this->makeAdmin();
        $this->promoRole();
        Carbon::setTestNow('2026-09-28 10:00:00');

        $data = $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/admin/users', [
                'name' => 'Promo Pat',
                'email' => 'pat@example.com',
                'password' => 'Str0ng-passw0rd',
                'role' => 'promotional_customer',
                'promo_days' => 14,
            ])
            ->assertCreated()
            ->json('data.user');

        $this->assertSame(['promotional_customer'], $data['roles']);
        $this->assertSame('2026-10-12T10:00:00.000000Z', $data['promo_expires_at']);

        $user = User::where('email', 'pat@example.com')->first();
        $this->assertNotNull($user->email_verified_at, 'admin-created users skip email verification');
        $this->assertNull($user->onboarding_completed_at, 'they still go through onboarding (minus the card step)');
        $this->assertTrue($user->hasActiveSubscription());

        // The new user can log in with the password the admin set.
        $this->postJson('/api/login', ['email' => 'pat@example.com', 'password' => 'Str0ng-passw0rd'])
            ->assertOk()
            ->assertJsonPath('data.user.has_active_subscription', true);

        Carbon::setTestNow();
    }

    public function test_admin_can_create_an_unlimited_promotional_customer(): void
    {
        $admin = $this->makeAdmin();
        $this->promoRole();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/admin/users', [
                'name' => 'Forever Fran',
                'email' => 'fran@example.com',
                'password' => 'Str0ng-passw0rd',
                'role' => 'promotional_customer',
                'promo_days' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.promo_expires_at', null)
            ->assertJsonPath('data.user.roles', ['promotional_customer']);

        $this->assertTrue(User::where('email', 'fran@example.com')->first()->hasActiveSubscription());
    }

    public function test_admin_can_create_a_plain_customer(): void
    {
        $admin = $this->makeAdmin();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/admin/users', [
                'name' => 'Plain Pam',
                'email' => 'pam@example.com',
                'password' => 'Str0ng-passw0rd',
                'role' => 'customer',
                'promo_days' => 7, // ignored for non-promo roles
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.roles', ['customer'])
            ->assertJsonPath('data.user.promo_expires_at', null);

        $this->assertFalse(User::where('email', 'pam@example.com')->first()->hasActiveSubscription());
    }

    public function test_create_user_validates_input(): void
    {
        $admin = $this->makeAdmin();
        $this->promoRole();
        User::factory()->create(['email' => 'taken@example.com']);
        $headers = $this->tokenHeaders($admin);

        $base = ['name' => 'X', 'email' => 'new@example.com', 'password' => 'Str0ng-passw0rd', 'role' => 'promotional_customer'];

        $this->withHeaders($headers)->postJson('/api/admin/users', array_merge($base, ['email' => 'taken@example.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->withHeaders($headers)->postJson('/api/admin/users', array_merge($base, ['password' => 'short']))
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->withHeaders($headers)->postJson('/api/admin/users', array_merge($base, ['role' => 'admin']))
            ->assertStatus(422)->assertJsonValidationErrors('role');
        $this->withHeaders($headers)->postJson('/api/admin/users', array_merge($base, ['promo_days' => 0]))
            ->assertStatus(422)->assertJsonValidationErrors('promo_days');
    }

    public function test_non_admin_cannot_create_users(): void
    {
        $user = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($user))
            ->postJson('/api/admin/users', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'Str0ng-passw0rd', 'role' => 'customer'])
            ->assertForbidden();
    }

    public function test_role_change_to_promotional_sets_the_free_window(): void
    {
        $admin = $this->makeAdmin();
        $this->promoRole();
        $target = User::factory()->create();
        Carbon::setTestNow('2026-09-28 10:00:00');

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'promotional_customer', 'promo_days' => 30])
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['promotional_customer'])
            ->assertJsonPath('data.user.promo_expires_at', '2026-10-28T10:00:00.000000Z');

        // Omitting promo_days keeps the existing window.
        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'promotional_customer'])
            ->assertOk()
            ->assertJsonPath('data.user.promo_expires_at', '2026-10-28T10:00:00.000000Z');

        // Explicit null = unlimited.
        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'promotional_customer', 'promo_days' => null])
            ->assertOk()
            ->assertJsonPath('data.user.promo_expires_at', null);

        Carbon::setTestNow();
    }

    public function test_role_change_away_from_promotional_clears_the_free_window(): void
    {
        $admin = $this->makeAdmin();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);
        $target = User::factory()->create(['promo_expires_at' => now()->addDays(3)]);
        $target->roles()->attach($this->promoRole()->id);

        $this->withHeaders($this->tokenHeaders($admin))
            ->putJson("/api/admin/users/{$target->id}/role", ['role' => 'customer'])
            ->assertOk()
            ->assertJsonPath('data.user.roles', ['customer'])
            ->assertJsonPath('data.user.promo_expires_at', null);

        $this->assertNull($target->fresh()->promo_expires_at);
    }

    public function test_index_exposes_role_and_promo_window(): void
    {
        $admin = $this->makeAdmin();
        $promo = User::factory()->create(['email' => 'promo@example.com', 'promo_expires_at' => Carbon::parse('2026-12-01 00:00:00')]);
        $promo->roles()->attach($this->promoRole()->id);

        $rows = collect($this->withHeaders($this->tokenHeaders($admin))
            ->getJson('/api/admin/users')
            ->assertOk()
            ->json('data.users'));

        $row = $rows->firstWhere('email', 'promo@example.com');
        $this->assertSame('promotional_customer', $row['role']);
        $this->assertSame('2026-12-01T00:00:00.000000Z', $row['promo_expires_at']);
        $this->assertFalse($row['subscribed']);
    }

    public function test_admin_created_users_are_tagged_with_the_admin_signup_source(): void
    {
        $admin = $this->makeAdmin();
        Role::firstOrCreate(['name' => 'customer'], ['display_name' => 'Customer']);

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson('/api/admin/users', ['name' => 'Made By Hand', 'email' => 'hand@example.com', 'password' => 'password123', 'role' => 'customer'])
            ->assertStatus(201);

        $this->assertDatabaseHas('users', ['email' => 'hand@example.com', 'signup_source' => 'admin', 'signup_client' => null]);
    }

    public function test_index_reports_and_filters_by_signup_source_treating_null_as_app(): void
    {
        $admin = $this->makeAdmin();
        $day = '2026-06-05 12:00:00';
        User::factory()->create(['email' => 'legacy@example.com', 'created_at' => $day, 'signup_source' => null]);
        User::factory()->create(['email' => 'app@example.com', 'created_at' => $day, 'signup_source' => 'app']);
        User::factory()->create(['email' => 'agent@example.com', 'created_at' => $day, 'signup_source' => 'agent', 'signup_client' => 'Claude']);
        User::factory()->create(['email' => 'byhand@example.com', 'created_at' => $day, 'signup_source' => 'admin']);

        $rows = fn (string $qs) => collect($this->withHeaders($this->tokenHeaders($admin))
            ->getJson('/api/admin/users?'.self::WINDOW.$qs)->assertOk()->json('data.users'));

        $all = $rows('');
        $this->assertSame('app', $all->firstWhere('email', 'legacy@example.com')['signup_source']);
        $this->assertSame('Claude', $all->firstWhere('email', 'agent@example.com')['signup_client']);

        $this->assertEqualsCanonicalizing(['legacy@example.com', 'app@example.com'], $rows('&source=app')->pluck('email')->all());
        $this->assertEqualsCanonicalizing(['agent@example.com', 'byhand@example.com'], $rows('&source=agent,admin')->pluck('email')->all());
        $this->assertSame(['admin', 'agent', 'app', 'app'], $rows('&sort=signup_source&dir=asc')->pluck('signup_source')->all());
    }

    public function test_stats_count_agent_signups(): void
    {
        $headers = $this->adminHeaders();
        $day = '2026-06-05 12:00:00';
        User::factory()->create(['created_at' => $day, 'signup_source' => 'agent', 'signup_client' => 'Claude']);
        User::factory()->create(['created_at' => $day]);

        $res = $this->withHeaders($headers)->getJson('/api/admin/users/stats?'.self::WINDOW)->assertOk();

        $res->assertJsonPath('data.totals.signups', 2)->assertJsonPath('data.totals.agent_signups', 1);
        $this->assertSame(1, collect($res->json('data.series'))->sum('agent_signups'));
    }

    // ---- Log in as user (impersonation) --------------------------------------

    public function test_admin_can_log_in_as_a_customer(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create(['email' => 'customer@example.com']);
        Log::shouldReceive('channel')->once()->with('impersonation')->andReturn($spy = \Mockery::mock(\Psr\Log\LoggerInterface::class));
        $spy->shouldReceive('info')->once()->withArgs(fn ($msg, $ctx) => $ctx['admin_id'] === $admin->id && $ctx['user_id'] === $target->id);

        $res = $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/admin/users/{$target->id}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.user.email', 'customer@example.com')
            ->assertJsonPath('data.token_type', 'Bearer');

        // The token belongs to the target, is labelled, and expires in about an hour.
        $token = $res->json('data.token');
        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'customer@example.com');

        $row = $target->tokens()->first();
        $this->assertStringStartsWith('impersonation:by:'.$admin->id, $row->name);
        $this->assertEqualsWithDelta(now()->addHour()->getTimestamp(), $row->expires_at->getTimestamp(), 60);
        $this->assertNull($target->fresh()->last_login_at, 'impersonation must not count as the user logging in');
    }

    public function test_admin_cannot_log_in_as_self_or_another_admin(): void
    {
        $admin = $this->makeAdmin();
        $other = $this->makeAdmin();

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/admin/users/{$admin->id}/impersonate")
            ->assertStatus(422);

        $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/admin/users/{$other->id}/impersonate")
            ->assertStatus(403);

        $this->assertSame(0, $other->tokens()->count());
    }

    public function test_non_admin_cannot_log_in_as_anyone(): void
    {
        $user = User::factory()->create();
        $target = User::factory()->create();

        $this->withHeaders($this->tokenHeaders($user))
            ->postJson("/api/admin/users/{$target->id}/impersonate")
            ->assertStatus(403);
    }

    public function test_returning_to_admin_revokes_the_impersonation_token(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $token = $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/admin/users/{$target->id}/impersonate")
            ->json('data.token');
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

        $this->withHeaders($headers)->postJson('/api/logout')->assertOk();

        $this->withHeaders($headers)->getJson('/api/profile')->assertStatus(401);
        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_expired_impersonation_token_is_rejected(): void
    {
        $admin = $this->makeAdmin();
        $target = User::factory()->create();

        $token = $this->withHeaders($this->tokenHeaders($admin))
            ->postJson("/api/admin/users/{$target->id}/impersonate")
            ->json('data.token');

        $this->travel(61)->minutes();

        $this->withHeaders(['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'])
            ->getJson('/api/profile')
            ->assertStatus(401);
    }
}
