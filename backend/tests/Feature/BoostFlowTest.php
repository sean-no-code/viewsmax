<?php

namespace Tests\Feature;

use App\Jobs\ProcessBoostCheckJob;
use App\Models\BoostCheck;
use App\Models\BoostSetting;
use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Boosts (X-only v1): once a post reaches N likes, Auto Repost retweets it and
 * Auto Promo replies with a promo comment. Checks run at +6h intervals, up to
 * 3 times, and stop early on success. One boost per post per feature, ever.
 */
class BoostFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('test-token')->plainTextToken;
        $this->account = $this->user->socialAccounts()->create([
            'platform' => 'x',
            'platform_account_id' => 'x-user-1',
            'name' => 'Tester',
            'username' => 'tester',
            'access_token' => 'token',
            'token_expires_at' => now()->addDay(),
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json'];
    }

    private function enable(string $feature, int $threshold = 10, ?string $promo = null): BoostSetting
    {
        return BoostSetting::create([
            'user_id' => $this->user->id,
            'social_account_id' => $this->account->id,
            'feature' => $feature,
            'enabled' => true,
            'likes_threshold' => $threshold,
            'promo_text' => $promo,
        ]);
    }

    private function publishTarget(): PostTarget
    {
        $post = Post::create([
            'user_id' => $this->user->id,
            'caption' => 'boost me',
            'media' => [],
            'status' => Post::STATUS_POSTED,
        ]);
        $target = $post->targets()->create([
            'platform' => 'x',
            'social_account_id' => $this->account->id,
            'status' => PostTarget::STATUS_PUBLISHING,
        ]);
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHED,
            'platform_post_id' => 'tweet-1',
            'published_at' => now(),
        ])->save();

        return $target;
    }

    private function metricsResponse(int $likes)
    {
        return Http::response(['data' => [['id' => 'tweet-1', 'public_metrics' => ['like_count' => $likes]]]]);
    }

    public function test_publish_seeds_checks_for_enabled_features_only(): void
    {
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST);
        // auto_promo exists but is disabled — no check for it.
        BoostSetting::create([
            'user_id' => $this->user->id,
            'social_account_id' => $this->account->id,
            'feature' => BoostSetting::FEATURE_AUTO_PROMO,
            'enabled' => false,
            'likes_threshold' => 10,
            'promo_text' => 'promo!',
        ]);

        $target = $this->publishTarget();

        $checks = BoostCheck::where('post_target_id', $target->id)->get();
        $this->assertCount(1, $checks);
        $this->assertSame(BoostSetting::FEATURE_AUTO_REPOST, $checks->first()->feature);
        $this->assertSame(BoostCheck::STATUS_PENDING, $checks->first()->status);
        $this->assertTrue($checks->first()->next_run_at->greaterThan(now()->addHours(5)));
    }

    public function test_publishing_twice_never_duplicates_checks(): void
    {
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST);
        $target = $this->publishTarget();
        // e.g. reconcile heals the target → published fires again
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHING])->save();
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHED])->save();

        $this->assertSame(1, BoostCheck::where('post_target_id', $target->id)->count());
    }

    public function test_threshold_reached_triggers_retweet_once(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets?*' => $this->metricsResponse(25),
            'api.twitter.com/2/users/x-user-1/retweets' => Http::response(['data' => ['retweeted' => true]]),
        ]);
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST, 10);
        $target = $this->publishTarget();
        $check = BoostCheck::where('post_target_id', $target->id)->firstOrFail();

        (new ProcessBoostCheckJob($check->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $check->refresh();
        $this->assertSame(BoostCheck::STATUS_TRIGGERED, $check->status, (string) $check->error);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/retweets') && ($r['tweet_id'] ?? null) === 'tweet-1');
    }

    public function test_threshold_reached_posts_promo_comment(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets?*' => $this->metricsResponse(50),
            'api.twitter.com/2/tweets' => Http::response(['data' => ['id' => 'promo-tweet']]),
        ]);
        $this->enable(BoostSetting::FEATURE_AUTO_PROMO, 20, 'Register for my newsletter!');
        $target = $this->publishTarget();
        $check = BoostCheck::where('post_target_id', $target->id)->firstOrFail();

        (new ProcessBoostCheckJob($check->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $check->refresh();
        $this->assertSame(BoostCheck::STATUS_TRIGGERED, $check->status, (string) $check->error);
        $this->assertSame('promo-tweet', $check->result_remote_id);
        Http::assertSent(function ($r) {
            return str_contains($r->url(), '/2/tweets')
                && ! str_contains($r->url(), '?')
                && ($r['text'] ?? null) === 'Register for my newsletter!'
                && ($r['reply']['in_reply_to_tweet_id'] ?? null) === 'tweet-1';
        });
    }

    public function test_below_threshold_three_times_exhausts_the_check(): void
    {
        Http::fake(['api.twitter.com/2/tweets?*' => $this->metricsResponse(3)]);
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST, 10);
        $target = $this->publishTarget();
        $check = BoostCheck::where('post_target_id', $target->id)->firstOrFail();

        foreach (range(1, 3) as $run) {
            (new ProcessBoostCheckJob($check->id))->handle(app(\App\Services\Social\SocialProviderManager::class));
            $check->refresh();
        }

        $this->assertSame(BoostCheck::STATUS_EXHAUSTED, $check->status);
        $this->assertSame(3, $check->runs_completed);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/retweets'));
    }

    public function test_duplicate_retweet_counts_as_triggered(): void
    {
        Http::fake([
            'api.twitter.com/2/tweets?*' => $this->metricsResponse(99),
            'api.twitter.com/2/users/x-user-1/retweets' => Http::response(['errors' => [['message' => 'You have already retweeted this Tweet.']]], 403),
        ]);
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST, 10);
        $target = $this->publishTarget();
        $check = BoostCheck::where('post_target_id', $target->id)->firstOrFail();

        (new ProcessBoostCheckJob($check->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $this->assertSame(BoostCheck::STATUS_TRIGGERED, $check->fresh()->status);
    }

    public function test_disabled_setting_cancels_inflight_check(): void
    {
        Http::fake();
        $setting = $this->enable(BoostSetting::FEATURE_AUTO_REPOST, 10);
        $target = $this->publishTarget();
        $check = BoostCheck::where('post_target_id', $target->id)->firstOrFail();
        $setting->update(['enabled' => false]);

        (new ProcessBoostCheckJob($check->id))->handle(app(\App\Services\Social\SocialProviderManager::class));

        $this->assertSame(BoostCheck::STATUS_EXHAUSTED, $check->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_boosts_run_command_dispatches_due_checks_only(): void
    {
        Queue::fake([ProcessBoostCheckJob::class]);
        $this->enable(BoostSetting::FEATURE_AUTO_REPOST, 10);
        $target = $this->publishTarget();
        $due = BoostCheck::where('post_target_id', $target->id)->firstOrFail();
        $due->forceFill(['next_run_at' => now()->subMinute()])->save();

        // A second, not-yet-due check on another post.
        $other = $this->publishTarget();
        BoostCheck::where('post_target_id', $other->id)->update(['next_run_at' => now()->addHours(5)]);

        $this->artisan('boosts:run')->assertExitCode(0);

        Queue::assertPushed(ProcessBoostCheckJob::class, 1);
    }

    public function test_settings_api_upserts_and_validates(): void
    {
        $this->withHeaders($this->auth())
            ->putJson("/api/boosts/settings/{$this->account->id}", [
                'feature' => 'auto_promo',
                'enabled' => true,
                'likes_threshold' => 20,
                'promo_text' => 'Check my newsletter',
            ])
            ->assertOk()
            ->assertJsonPath('data.likes_threshold', 20);

        // Upsert, not duplicate.
        $this->withHeaders($this->auth())
            ->putJson("/api/boosts/settings/{$this->account->id}", [
                'feature' => 'auto_promo',
                'enabled' => true,
                'likes_threshold' => 30,
                'promo_text' => 'Check my newsletter',
            ])
            ->assertOk();
        $this->assertSame(1, BoostSetting::where('social_account_id', $this->account->id)->count());

        // Promo text must fit a tweet.
        $this->withHeaders($this->auth())
            ->putJson("/api/boosts/settings/{$this->account->id}", [
                'feature' => 'auto_promo',
                'enabled' => true,
                'likes_threshold' => 10,
                'promo_text' => str_repeat('a', 300),
            ])
            ->assertStatus(422);

        // Enabling auto_promo without text is rejected.
        $this->withHeaders($this->auth())
            ->putJson("/api/boosts/settings/{$this->account->id}", [
                'feature' => 'auto_promo',
                'enabled' => true,
                'likes_threshold' => 10,
            ])
            ->assertStatus(422);

        // Foreign account is a 404.
        $foreign = User::factory()->create()->socialAccounts()->create([
            'platform' => 'x', 'platform_account_id' => 'x-9', 'access_token' => 't',
            'status' => SocialAccount::STATUS_CONNECTED,
        ]);
        $this->withHeaders($this->auth())
            ->putJson("/api/boosts/settings/{$foreign->id}", [
                'feature' => 'auto_repost', 'enabled' => true, 'likes_threshold' => 5,
            ])
            ->assertNotFound();

        $this->withHeaders($this->auth())
            ->getJson('/api/boosts/settings')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
