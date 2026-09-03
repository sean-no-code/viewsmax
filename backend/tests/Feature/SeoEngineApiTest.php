<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ViewsMax\SeoEngine\Models\SeoArticle;
use ViewsMax\SeoEngine\Models\SeoBacklinkProspect;
use ViewsMax\SeoEngine\Models\SeoKeyword;
use ViewsMax\SeoEngine\Models\SeoProfile;

/**
 * User-scoped SEO API: profile setup per offer (competitors + WordPress blog in
 * the app, NOT .env), scoped keyword/article/prospect lists, and ownership
 * checks — one user can never see or touch another user's SEO data.
 */
class SeoEngineApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->offer = Offer::create(['user_id' => $this->user->id, 'name' => 'Course', 'offer_url' => 'https://c.example']);
    }

    private function auth(?User $user = null): array
    {
        $user ??= $this->user;

        return ['Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken, 'Accept' => 'application/json'];
    }

    private function profile(): SeoProfile
    {
        return SeoProfile::create([
            'user_id' => $this->user->id,
            'tracking_event_id' => $this->offer->id,
            'competitors' => ['competitor.com'],
            'enabled' => true,
        ]);
    }

    public function test_user_creates_a_profile_for_their_offer_with_normalised_competitors(): void
    {
        $response = $this->withHeaders($this->auth())->putJson("/api/seo/profiles/offer/{$this->offer->id}", [
            'competitors' => ['https://www.Hootsuite.com/pricing', 'buffer.com'],
            'wp_url' => 'https://blog.c.example',
            'wp_username' => 'bot',
            'wp_app_password' => 'secret-app-pass',
            'articles_per_week' => 2,
            'enabled' => true,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.competitors', ['hootsuite.com', 'buffer.com'])
            ->assertJsonPath('data.has_wp_password', true)
            ->assertJsonMissingPath('data.wp_app_password'); // secret never returned

        $profile = SeoProfile::firstOrFail();
        $this->assertSame('secret-app-pass', $profile->wp_app_password); // encrypted cast round-trips
        $this->assertTrue($profile->enabled);
    }

    public function test_profile_rejects_a_private_wordpress_url(): void
    {
        foreach (['http://127.0.0.1:8000', 'http://169.254.169.254/latest', 'http://10.0.0.5'] as $url) {
            $this->withHeaders($this->auth())->putJson("/api/seo/profiles/offer/{$this->offer->id}", [
                'competitors' => ['competitor.com'],
                'wp_url' => $url,
            ])->assertStatus(422)->assertJsonValidationErrors('wp_url');
        }

        $this->assertSame(0, SeoProfile::count());
    }

    public function test_updating_without_password_keeps_the_stored_one(): void
    {
        $this->profile()->update(['wp_app_password' => 'keep-me']);

        $this->withHeaders($this->auth())->putJson("/api/seo/profiles/offer/{$this->offer->id}", [
            'competitors' => ['competitor.com'],
            'articles_per_week' => 5,
        ])->assertOk();

        $this->assertSame('keep-me', SeoProfile::first()->wp_app_password);
    }

    public function test_cannot_configure_someone_elses_offer(): void
    {
        $other = User::factory()->create();
        $foreignOffer = Offer::create(['user_id' => $other->id, 'name' => 'X', 'offer_url' => 'https://x.example']);

        $this->withHeaders($this->auth())->putJson("/api/seo/profiles/offer/{$foreignOffer->id}", [
            'competitors' => ['competitor.com'],
        ])->assertStatus(404);
    }

    public function test_lists_are_scoped_to_the_owned_profile(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'best tool', 'search_volume' => 500, 'intent_score' => 20]);
        $profile->articles()->create(['seo_keyword_id' => $kw->id, 'title' => 'Best Tool', 'slug' => 'best-tool', 'html' => '<p>.</p>', 'status' => SeoArticle::STATUS_REVIEW]);
        $profile->prospects()->create(['url' => 'https://bigblog.com/t', 'domain' => 'bigblog.com', 'competitor_domain' => 'competitor.com', 'domain_rank' => 300]);

        $auth = $this->auth();
        $this->withHeaders($auth)->getJson("/api/seo/profiles/{$profile->id}/keywords")->assertOk()->assertJsonPath('data.0.keyword', 'best tool');
        $this->withHeaders($auth)->getJson("/api/seo/profiles/{$profile->id}/articles")->assertOk()->assertJsonPath('data.0.title', 'Best Tool');
        $this->withHeaders($auth)->getJson("/api/seo/profiles/{$profile->id}/prospects")->assertOk()->assertJsonPath('data.0.domain', 'bigblog.com');

        // A different user cannot read this profile's data. (forgetGuards: the
        // Sanctum RequestGuard caches the first user across in-test requests.)
        \Illuminate\Support\Facades\Auth::forgetGuards();
        $this->withHeaders($this->auth(User::factory()->create()))
            ->getJson("/api/seo/profiles/{$profile->id}/keywords")->assertStatus(404);
    }

    public function test_user_approves_their_article_but_cannot_mark_it_published(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'k']);
        $article = $profile->articles()->create(['seo_keyword_id' => $kw->id, 'title' => 'T', 'slug' => 't', 'html' => '<p>.</p>', 'status' => SeoArticle::STATUS_REVIEW]);

        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", ['status' => 'queued'])->assertOk();
        $this->assertSame(SeoArticle::STATUS_QUEUED, $article->fresh()->status);

        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", ['status' => 'published'])->assertStatus(422);
    }

    public function test_foreign_rows_cannot_be_touched(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'k']);
        $prospect = $profile->prospects()->create(['url' => 'https://b.com/x', 'domain' => 'b.com', 'competitor_domain' => 'competitor.com']);

        \Illuminate\Support\Facades\Auth::forgetGuards();
        $stranger = $this->auth(User::factory()->create());
        $this->withHeaders($stranger)->patchJson("/api/seo/keywords/{$kw->id}", ['status' => 'skipped'])->assertStatus(404);
        $this->withHeaders($stranger)->patchJson("/api/seo/prospects/{$prospect->id}", ['status' => 'contacted'])->assertStatus(404);

        $this->assertSame(SeoKeyword::STATUS_DISCOVERED, $kw->fresh()->status ?? SeoKeyword::STATUS_DISCOVERED);
        $this->assertSame(SeoBacklinkProspect::STATUS_NEW, $prospect->fresh()->status);
    }

    public function test_user_edits_a_pre_publish_article_but_not_a_published_one(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'k']);
        $article = $profile->articles()->create(['seo_keyword_id' => $kw->id, 'title' => 'Old', 'slug' => 'old', 'html' => '<p>old</p>', 'status' => SeoArticle::STATUS_REVIEW]);

        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", [
            'title' => 'New Title',
            'meta_description' => 'Fresh meta',
            'category' => 'Guide: How-to',
            'html' => '<h2>New</h2><p>body</p>',
        ])->assertOk();

        $fresh = $article->fresh();
        $this->assertSame('New Title', $fresh->title);
        $this->assertSame('Guide: How-to', $fresh->category);
        $this->assertSame('<h2>New</h2><p>body</p>', $fresh->html);

        // Once published, content edits are refused (would diverge from WordPress).
        $article->update(['status' => SeoArticle::STATUS_PUBLISHED]);
        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", ['title' => 'Nope'])
            ->assertStatus(422);

        // Bogus category is rejected.
        $article->update(['status' => SeoArticle::STATUS_REVIEW]);
        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", ['category' => 'Listicle'])
            ->assertStatus(422);
    }

    public function test_user_retries_a_failed_article_which_requeues_and_clears_the_error(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'k']);
        $article = $profile->articles()->create([
            'seo_keyword_id' => $kw->id, 'title' => 'T', 'slug' => 't', 'html' => '<p>.</p>',
            'status' => SeoArticle::STATUS_FAILED, 'error' => 'WordPress publish failed (HTTP 401)',
        ]);

        $this->withHeaders($this->auth())->patchJson("/api/seo/articles/{$article->id}", ['status' => 'queued'])->assertOk();

        $fresh = $article->fresh();
        $this->assertSame(SeoArticle::STATUS_QUEUED, $fresh->status);
        $this->assertNull($fresh->error);
    }

    public function test_prospect_crm_updates(): void
    {
        $profile = $this->profile();
        $prospect = $profile->prospects()->create(['url' => 'https://b.com/x', 'domain' => 'b.com', 'competitor_domain' => 'competitor.com']);

        $this->withHeaders($this->auth())
            ->patchJson("/api/seo/prospects/{$prospect->id}", ['status' => 'contacted', 'notes' => 'emailed editor'])
            ->assertOk();

        $fresh = $prospect->fresh();
        $this->assertSame(SeoBacklinkProspect::STATUS_CONTACTED, $fresh->status);
        $this->assertSame('emailed editor', $fresh->notes);
    }

    public function test_bulk_prospect_status_update(): void
    {
        $profile = $this->profile();
        $a = $profile->prospects()->create(['url' => 'https://a.com/x', 'domain' => 'a.com', 'competitor_domain' => 'competitor.com']);
        $b = $profile->prospects()->create(['url' => 'https://b.com/x', 'domain' => 'b.com', 'competitor_domain' => 'competitor.com']);
        $c = $profile->prospects()->create(['url' => 'https://c.com/x', 'domain' => 'c.com', 'competitor_domain' => 'competitor.com']);

        $this->withHeaders($this->auth())
            ->patchJson('/api/seo/prospects', ['ids' => [$a->id, $b->id], 'status' => 'contacted'])
            ->assertOk()
            ->assertJsonPath('data.updated', 2);

        $this->assertSame(SeoBacklinkProspect::STATUS_CONTACTED, $a->fresh()->status);
        $this->assertSame(SeoBacklinkProspect::STATUS_CONTACTED, $b->fresh()->status);
        $this->assertSame(SeoBacklinkProspect::STATUS_NEW, $c->fresh()->status);
    }

    public function test_bulk_update_silently_skips_prospects_the_caller_does_not_own(): void
    {
        $own = $this->profile()->prospects()->create(['url' => 'https://a.com/x', 'domain' => 'a.com', 'competitor_domain' => 'competitor.com']);

        $stranger = User::factory()->create();
        $foreignOffer = Offer::create(['user_id' => $stranger->id, 'name' => 'X', 'offer_url' => 'https://x.example']);
        $foreignProfile = SeoProfile::create(['user_id' => $stranger->id, 'tracking_event_id' => $foreignOffer->id, 'competitors' => ['z.com'], 'enabled' => true]);
        $foreign = $foreignProfile->prospects()->create(['url' => 'https://f.com/x', 'domain' => 'f.com', 'competitor_domain' => 'z.com']);

        $this->withHeaders($this->auth())
            ->patchJson('/api/seo/prospects', ['ids' => [$own->id, $foreign->id], 'status' => 'won'])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertSame(SeoBacklinkProspect::STATUS_WON, $own->fresh()->status);
        $this->assertSame(SeoBacklinkProspect::STATUS_NEW, $foreign->fresh()->status);
    }

    public function test_bulk_update_rejects_unknown_status(): void
    {
        $prospect = $this->profile()->prospects()->create(['url' => 'https://a.com/x', 'domain' => 'a.com', 'competitor_domain' => 'competitor.com']);

        $this->withHeaders($this->auth())
            ->patchJson('/api/seo/prospects', ['ids' => [$prospect->id], 'status' => 'bogus'])
            ->assertStatus(422);

        $this->assertSame(SeoBacklinkProspect::STATUS_NEW, $prospect->fresh()->status);
    }
}
