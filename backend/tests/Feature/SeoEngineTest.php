<?php

namespace Tests\Feature;

use App\Models\Offer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ViewsMax\SeoEngine\Models\SeoArticle;
use ViewsMax\SeoEngine\Models\SeoBacklinkProspect;
use ViewsMax\SeoEngine\Models\SeoKeyword;
use ViewsMax\SeoEngine\Models\SeoProfile;

/**
 * The viewsmax/seo-engine pipeline, multi-tenant: each USER configures a
 * profile per offer (their competitors, their WordPress blog, their cadence)
 * and every stage runs per enabled profile. Global kill-switch still freezes
 * everything.
 */
class SeoEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Offer $offer;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'seo-engine.enabled' => true,
            'seo-engine.dataforseo.login' => 'dfs-login',
            'seo-engine.dataforseo.password' => 'dfs-pass',
            'services.anthropic.api_key' => 'ant-key',
            // Off by default so non-image tests never call the images API;
            // image tests opt back in.
            'seo-engine.images.enabled' => false,
        ]);
        $this->user = User::factory()->create();
        $this->offer = Offer::create(['user_id' => $this->user->id, 'name' => 'My Course', 'offer_url' => 'https://mycourse.example/join']);
    }

    private function profile(array $overrides = []): SeoProfile
    {
        return SeoProfile::create(array_merge([
            'user_id' => $this->user->id,
            'tracking_event_id' => $this->offer->id,
            'competitors' => ['competitor.com'],
            'wp_url' => 'https://blog.example',
            'wp_username' => 'bot',
            'wp_app_password' => 'app-pass',
            'articles_per_week' => 2,
            'auto_publish' => true,
            'enabled' => true,
        ], $overrides));
    }

    private function fakeRankedKeywords(array $items): void
    {
        Http::fake([
            'api.dataforseo.com/v3/dataforseo_labs/*' => Http::response([
                'tasks' => [['result' => [['items' => $items]]]],
            ]),
        ]);
    }

    private function dfsKeyword(string $kw, int $volume, int $difficulty, float $cpc): array
    {
        return [
            'keyword_data' => [
                'keyword' => $kw,
                'keyword_info' => ['search_volume' => $volume, 'cpc' => $cpc],
                'keyword_properties' => ['keyword_difficulty' => $difficulty],
            ],
            'ranked_serp_element' => ['serp_item' => ['url' => 'https://competitor.com/page']],
        ];
    }

    public function test_kill_switch_stops_every_stage_without_calling_any_vendor(): void
    {
        config(['seo-engine.enabled' => false]);
        $this->profile();
        Http::fake();

        $this->artisan('seo:run')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(0, SeoKeyword::count());
    }

    public function test_disabled_profile_is_skipped(): void
    {
        $this->profile(['enabled' => false]);
        Http::fake();

        $this->artisan('seo:discover-keywords')->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_discovery_scopes_high_intent_keywords_to_the_profile(): void
    {
        $profile = $this->profile();
        $this->fakeRankedKeywords([
            $this->dfsKeyword('best competitor alternative', 900, 30, 3.2),  // keep
            $this->dfsKeyword('what is social media', 5000, 20, 0.1),        // drop: no intent
            $this->dfsKeyword('best scheduler for tiktok', 10, 10, 2.0),     // drop: volume
            $this->dfsKeyword('social media tool comparison', 400, 80, 1.0), // drop: difficulty
        ]);

        $this->artisan('seo:discover-keywords')->assertExitCode(0);

        $this->assertSame(1, SeoKeyword::count());
        $kw = SeoKeyword::first();
        $this->assertSame('best competitor alternative', $kw->keyword);
        $this->assertSame($profile->id, $kw->seo_profile_id);
    }

    public function test_drafting_uses_the_offer_identity_and_queues_per_profile(): void
    {
        $profile = $this->profile();
        $profile->keywords()->create(['keyword' => 'best competitor alternative', 'search_volume' => 900, 'intent_score' => 30]);
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => json_encode([
                    'title' => 'The 7 Best Competitor Alternatives in 2026',
                    'slug' => 'best-competitor-alternatives',
                    'meta_description' => 'We compared the top alternatives.',
                    'html' => '<h2>Intro</h2><p>Real comparison…</p>',
                ])]],
            ]),
        ]);

        $this->artisan('seo:draft-articles')->assertExitCode(0);

        $article = SeoArticle::firstOrFail();
        $this->assertSame($profile->id, $article->seo_profile_id);
        $this->assertSame(SeoArticle::STATUS_QUEUED, $article->status);
        // Anthropic response carried no category -> classified from keyword shape.
        $this->assertSame('List: Round-up', $article->category);
        // The prompt promotes the OFFER, not a global .env site, and anchors
        // the model to today's date so titles aren't stamped with stale years.
        Http::assertSent(fn ($req) => str_contains($req->url(), 'anthropic')
            && str_contains(json_encode($req->data()), 'My Course')
            && str_contains(json_encode($req->data()), 'mycourse.example')
            && str_contains(json_encode($req->data()), "Today's date is ".now()->format('F j, Y'))
            && str_contains(json_encode($req->data()), 'must be '.now()->format('Y')));
    }

    public function test_auto_publish_off_holds_drafts_in_review(): void
    {
        $profile = $this->profile(['auto_publish' => false]);
        $profile->keywords()->create(['keyword' => 'k', 'search_volume' => 100, 'intent_score' => 10]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['title' => 'T', 'slug' => 't', 'meta_description' => 'm', 'html' => '<p>x</p>'])]],
        ])]);

        $this->artisan('seo:draft-articles');

        $this->assertSame(SeoArticle::STATUS_REVIEW, SeoArticle::first()->status);
    }

    public function test_publishing_uses_the_profiles_wordpress_and_honours_its_weekly_cap(): void
    {
        $profile = $this->profile(['articles_per_week' => 2]);
        Http::fake(['blog.example/wp-json/*' => Http::response(['id' => 77, 'link' => 'https://blog.example/best-alt'])]);

        $kw = $profile->keywords()->create(['keyword' => 'k1', 'status' => SeoKeyword::STATUS_DRAFTED]);
        $mk = fn (string $slug, string $status, $publishedAt = null) => $kw->articles()->create([
            'seo_profile_id' => $profile->id, 'title' => $slug, 'slug' => $slug, 'html' => '<p>.</p>',
            'status' => $status, 'published_at' => $publishedAt,
        ]);
        $mk('old', SeoArticle::STATUS_PUBLISHED, now()->subDay()); // eats 1 of the weekly 2
        $a1 = $mk('a1', SeoArticle::STATUS_QUEUED);
        $a2 = $mk('a2', SeoArticle::STATUS_QUEUED);

        $this->artisan('seo:publish-articles')->assertExitCode(0);

        $this->assertSame(SeoArticle::STATUS_PUBLISHED, $a1->fresh()->status);
        $this->assertSame('77', $a1->fresh()->wordpress_post_id);
        $this->assertSame(SeoArticle::STATUS_QUEUED, $a2->fresh()->status); // capped
        Http::assertSentCount(1);
        // Auth went to the PROFILE's blog with the PROFILE's app password.
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://blog.example/wp-json/')
            && $req->header('Authorization')[0] === 'Basic '.base64_encode('bot:app-pass'));
    }

    public function test_publishing_refuses_a_private_wordpress_url_and_never_calls_it(): void
    {
        // Stored directly (bypassing the API validation) to prove the worker guards itself too.
        $profile = $this->profile();
        $profile->forceFill(['wp_url' => 'http://169.254.169.254'])->save();
        Http::fake();

        $kw = $profile->keywords()->create(['keyword' => 'k1', 'status' => SeoKeyword::STATUS_DRAFTED]);
        $article = $kw->articles()->create([
            'seo_profile_id' => $profile->id, 'title' => 'a', 'slug' => 'a', 'html' => '<p>.</p>',
            'status' => SeoArticle::STATUS_QUEUED,
        ]);

        $this->artisan('seo:publish-articles');

        Http::assertNothingSent();
        $this->assertSame(SeoArticle::STATUS_FAILED, $article->fresh()->status);
        $this->assertStringContainsString('must be a public http(s) address', $article->fresh()->error);
    }

    public function test_publish_failure_does_not_echo_the_remote_response_body(): void
    {
        $profile = $this->profile();
        Http::fake(['blog.example/wp-json/*' => Http::response('<html>internal admin panel</html>', 500)]);

        $kw = $profile->keywords()->create(['keyword' => 'k1', 'status' => SeoKeyword::STATUS_DRAFTED]);
        $article = $kw->articles()->create([
            'seo_profile_id' => $profile->id, 'title' => 'a', 'slug' => 'a', 'html' => '<p>.</p>',
            'status' => SeoArticle::STATUS_QUEUED,
        ]);

        $this->artisan('seo:publish-articles');

        $fresh = $article->fresh();
        $this->assertSame(SeoArticle::STATUS_FAILED, $fresh->status);
        $this->assertStringContainsString('HTTP 500', $fresh->error);
        $this->assertStringNotContainsString('internal admin panel', $fresh->error);
    }

    public function test_prospecting_scopes_to_profile_and_skips_low_rank_and_own_offer_domain(): void
    {
        $profile = $this->profile();
        Http::fake([
            'api.dataforseo.com/v3/backlinks/*' => Http::response([
                'tasks' => [['result' => [['items' => [
                    ['url_from' => 'https://bigblog.com/tools', 'domain_from' => 'bigblog.com', 'url_to' => 'https://competitor.com', 'anchor' => 'tool', 'domain_from_rank' => 350, 'dofollow' => true],
                    ['url_from' => 'https://tiny.net/x', 'domain_from' => 'tiny.net', 'url_to' => 'https://competitor.com', 'anchor' => 'x', 'domain_from_rank' => 5, 'dofollow' => true],
                    ['url_from' => 'https://mycourse.example/blog', 'domain_from' => 'mycourse.example', 'url_to' => 'https://competitor.com', 'anchor' => 'y', 'domain_from_rank' => 400, 'dofollow' => true],
                ]]]]],
            ]),
        ]);

        $this->artisan('seo:prospect-backlinks')->assertExitCode(0);

        $this->assertSame(1, SeoBacklinkProspect::count());
        $p = SeoBacklinkProspect::first();
        $this->assertSame('bigblog.com', $p->domain);
        $this->assertSame($profile->id, $p->seo_profile_id);
    }

    public function test_drafting_illustrates_articles_with_a_featured_image_and_inline_figures(): void
    {
        config(['seo-engine.images.enabled' => true, 'services.openai.api_key' => 'oa-key', 'seo-engine.images.per_article' => 1]);
        Storage::fake('public');
        $profile = $this->profile();
        $profile->keywords()->create(['keyword' => 'k', 'search_volume' => 100, 'intent_score' => 10]);
        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => json_encode([
                'title' => 'T', 'slug' => 't', 'meta_description' => 'm', 'html' => '<h2>Section A</h2><p>x</p><h2>Section B</h2><p>y</p>',
            ])]]]),
            'api.openai.com/*' => Http::response(['data' => [['b64_json' => base64_encode('png-bytes')]]]),
        ]);

        $this->artisan('seo:draft-articles')->assertExitCode(0);

        $article = SeoArticle::firstOrFail();
        $this->assertNotNull($article->featured_image_url);
        $this->assertStringContainsString('<figure><img src=', $article->html);
        // 1 Anthropic draft + 2 OpenAI images (featured + one section figure).
        Http::assertSentCount(3);
        $this->assertCount(2, Storage::disk('public')->allFiles('seo-articles'));
    }

    public function test_image_kill_switch_skips_generation_entirely(): void
    {
        config(['seo-engine.images.enabled' => false, 'services.openai.api_key' => 'oa-key']);
        $profile = $this->profile();
        $profile->keywords()->create(['keyword' => 'k', 'search_volume' => 100, 'intent_score' => 10]);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['title' => 'T', 'slug' => 't', 'meta_description' => 'm', 'html' => '<h2>A</h2><p>x</p>'])]],
        ])]);

        $this->artisan('seo:draft-articles');

        $this->assertNull(SeoArticle::first()->featured_image_url);
        Http::assertSentCount(1); // anthropic only — no OpenAI calls
    }

    public function test_publishing_sideloads_the_featured_image_as_wordpress_media(): void
    {
        $profile = $this->profile();
        $kw = $profile->keywords()->create(['keyword' => 'k', 'status' => SeoKeyword::STATUS_DRAFTED]);
        $article = $kw->articles()->create([
            'seo_profile_id' => $profile->id, 'title' => 'T', 'slug' => 't', 'html' => '<p>.</p>',
            'featured_image_url' => 'https://cdn.example/hero.png', 'status' => SeoArticle::STATUS_QUEUED,
        ]);
        Http::fake([
            'cdn.example/*' => Http::response('png-bytes', 200, ['Content-Type' => 'image/png']),
            'blog.example/wp-json/wp/v2/media' => Http::response(['id' => 55]),
            'blog.example/wp-json/wp/v2/posts' => Http::response(['id' => 77, 'link' => 'https://blog.example/t']),
        ]);

        $this->artisan('seo:publish-articles')->assertExitCode(0);

        $this->assertSame(SeoArticle::STATUS_PUBLISHED, $article->fresh()->status);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/wp-json/wp/v2/media')
            && $req->header('Content-Disposition')[0] === 'attachment; filename="t.png"');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/wp-json/wp/v2/posts')
            && $req['featured_media'] === 55);
    }
}
