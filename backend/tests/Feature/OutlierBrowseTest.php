<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\OutlierVideo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutlierBrowseTest extends TestCase
{
    use RefreshDatabase;

    private function authHeaders(): array
    {
        $user = User::factory()->create();
        $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'password'])
            ->json('data.token');

        return ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
    }

    private function seedMixedPlatforms(): void
    {
        $yt = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC1', 'channel_name' => 'Tuber', 'subscriber_count' => 1000]);
        $ig = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'ig1', 'channel_name' => 'Grammer', 'subscriber_count' => null]);

        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'yt1',
            'title' => 'YT winner', 'thumbnail_url' => 'x', 'views' => 100000,
            'outlier_score' => 55, 'published_at' => now()->subDay(),
        ]);
        OutlierVideo::create([
            'platform' => 'instagram', 'channel_id' => $ig->id, 'youtube_video_id' => 'igshort1',
            'title' => 'IG reel', 'thumbnail_url' => 'x', 'views' => null,
            'outlier_score' => null, 'published_at' => now()->subDays(2),
        ]);
    }

    public function test_browse_without_platform_blends_all_platforms(): void
    {
        $this->seedMixedPlatforms();

        // No platform filter: the min-score gate must not drop Instagram rows
        // (they have no views-based score).
        $data = $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?min_score=20')
            ->assertOk()
            ->json('data');

        $platforms = collect($data)->pluck('platform')->sort()->values()->all();
        $this->assertSame(['instagram', 'youtube'], $platforms);
    }

    public function test_channel_scoped_browse_skips_the_default_score_gate(): void
    {
        // A channel pulled in via "add channel" stores every recent video, most
        // of them well under the 20x outlier threshold. Scoping to that channel
        // means "show me this creator's recent output ranked", so the default
        // gate is skipped — but only when no explicit min_score is sent.
        $ch = OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => 'tt-creator', 'channel_name' => 'Creator', 'average_views' => 300]);
        $other = OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => 'tt-other', 'channel_name' => 'Other']);
        $base = ['platform' => 'tiktok', 'thumbnail_url' => 'x', 'published_at' => now(), 'is_short' => true];
        OutlierVideo::create($base + ['channel_id' => $ch->id, 'youtube_video_id' => 'low', 'title' => 'Low', 'views' => 100, 'outlier_score' => 0.3]);
        OutlierVideo::create($base + ['channel_id' => $ch->id, 'youtube_video_id' => 'high', 'title' => 'High', 'views' => 9000, 'outlier_score' => 30]);
        OutlierVideo::create($base + ['channel_id' => $other->id, 'youtube_video_id' => 'otherlow', 'title' => 'Other low', 'views' => 100, 'outlier_score' => 0.5]);
        $headers = $this->authHeaders();

        $scoped = collect($this->withHeaders($headers)->getJson('/api/outliers?channels[]=tt-creator&duration_type=shorts')->assertOk()->json('data'))
            ->pluck('youtube_video_id')->sort()->values()->all();
        $this->assertSame(['high', 'low'], $scoped);

        // Explicit min_score still wins inside a channel scope.
        $strict = collect($this->withHeaders($headers)->getJson('/api/outliers?channels[]=tt-creator&duration_type=shorts&min_score=20')->json('data'))
            ->pluck('youtube_video_id')->all();
        $this->assertSame(['high'], $strict);

        // Unscoped browse keeps gating: bulk-ingested low scorers stay out of everyone's feed.
        $unscoped = collect($this->withHeaders($headers)->getJson('/api/outliers?duration_type=shorts')->json('data'))
            ->pluck('youtube_video_id')->all();
        $this->assertSame(['high'], $unscoped);
    }

    private function seedCountries(): void
    {
        $us = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCus', 'channel_name' => 'US Tuber', 'subscriber_count' => 1000, 'country' => 'US']);
        $gb = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCgb', 'channel_name' => 'GB Tuber', 'subscriber_count' => 1000, 'country' => 'GB']);
        $none = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCnone', 'channel_name' => 'No Country', 'subscriber_count' => 1000]);
        $base = ['platform' => 'youtube', 'thumbnail_url' => 'x', 'views' => 100000, 'outlier_score' => 55, 'published_at' => now()];

        OutlierVideo::create($base + ['channel_id' => $us->id, 'youtube_video_id' => 'vidUS', 'title' => 'US video']);
        OutlierVideo::create($base + ['channel_id' => $gb->id, 'youtube_video_id' => 'vidGB', 'title' => 'GB video']);
        OutlierVideo::create($base + ['channel_id' => $none->id, 'youtube_video_id' => 'vidNone', 'title' => 'Nowhere video']);
    }

    public function test_countries_filter_returns_only_matching_channels(): void
    {
        $this->seedCountries();

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?countries[]=US&countries[]=GB')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertContains('vidUS', $ids);
        $this->assertContains('vidGB', $ids);
        $this->assertNotContains('vidNone', $ids); // NULL-country channels are excluded when filtering
    }

    public function test_countries_filter_normalizes_lowercase_codes(): void
    {
        $this->seedCountries();

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?countries[]=us')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertSame(['vidUS'], $ids->all());
    }

    public function test_countries_filter_rejects_non_iso_codes(): void
    {
        $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?countries[]=usa')
            ->assertStatus(422);
    }

    public function test_browse_with_platform_still_filters(): void
    {
        $this->seedMixedPlatforms();

        $data = $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?platform=youtube&min_score=20')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('youtube', $data[0]['platform']);
    }

    /** YouTube Data API fakes for one pasted video + the /shorts/ format probe response. */
    private function fakeYouTubeVideoApi(int $shortsStatus, array $shortsHeaders = []): void
    {
        config()->set('services.youtube.key', 'yt_test_key');

        \Illuminate\Support\Facades\Http::fake([
            'www.youtube.com/shorts/*' => \Illuminate\Support\Facades\Http::response('', $shortsStatus, $shortsHeaders),
            '*/youtube/v3/videos*' => \Illuminate\Support\Facades\Http::response(['items' => [[
                'id' => 'dQw4w9WgXcQ',
                'snippet' => [
                    'title' => 'Pasted video',
                    'channelId' => 'UCabc123',
                    'publishedAt' => '2026-08-01T10:00:00Z',
                    'thumbnails' => ['medium' => ['url' => 'https://example.com/m.jpg'], 'default' => ['url' => 'https://example.com/d.jpg']],
                ],
                'statistics' => ['viewCount' => '5000', 'likeCount' => '100', 'commentCount' => '10'],
                'contentDetails' => ['duration' => 'PT2M5S'],
            ]]], 200),
            '*/youtube/v3/channels*' => \Illuminate\Support\Facades\Http::response(['items' => [[
                'snippet' => ['title' => 'Pasted Channel', 'country' => 'US', 'thumbnails' => ['high' => ['url' => 'https://example.com/av.jpg']]],
                'statistics' => ['subscriberCount' => '9000', 'videoCount' => '12'],
            ]]], 200),
            '*/youtube/v3/playlistItems*' => \Illuminate\Support\Facades\Http::response(['items' => []], 200),
        ]);
    }

    public function test_fetch_by_url_ingests_a_youtube_video(): void
    {
        // Long-form: YouTube redirects /shorts/{id} → /watch?v={id}.
        $this->fakeYouTubeVideoApi(303, ['Location' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ']);

        // 5000 views with no channel average → score 0, far below the outlier
        // threshold — a deliberate URL add must be stored anyway.
        \Illuminate\Support\Facades\Queue::fake();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('video_id', 'dQw4w9WgXcQ');

        app()->call([new \App\Jobs\IngestOutlierByUrlJob('youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'), 'handle']);

        $video = OutlierVideo::where('youtube_video_id', 'dQw4w9WgXcQ')->first();
        $this->assertNotNull($video);
        $this->assertSame('Pasted video', $video->title);
        $this->assertTrue($video->manually_added);
        // URL adds are classified inline so the breakdown page gets the right shape immediately.
        $this->assertFalse($video->is_short);
        $this->assertNotNull($video->format_checked_at);
        // The channel's country comes along from the YT snippet for the countries filter.
        $this->assertSame('US', $video->channel->country);
    }

    public function test_fetch_by_url_marks_a_real_short(): void
    {
        $this->fakeYouTubeVideoApi(200); // a genuine Short answers 200 at /shorts/{id}
        \Illuminate\Support\Facades\Queue::fake();

        app()->call([new \App\Jobs\IngestOutlierByUrlJob('youtube', 'https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'), 'handle']);

        $this->assertTrue(OutlierVideo::where('youtube_video_id', 'dQw4w9WgXcQ')->first()->is_short);
    }

    /** Rows covering every classification state, all above the score gate. */
    private function seedFormatRows(): void
    {
        $yt = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCf', 'channel_name' => 'Tuber', 'subscriber_count' => 1000]);
        $tt = OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => 'ttf', 'channel_name' => 'Tokker', 'subscriber_count' => 1000]);
        $ig = OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'igf', 'channel_name' => 'Grammer', 'subscriber_count' => null]);
        $base = ['thumbnail_url' => 'x', 'published_at' => now(), 'outlier_score' => 50, 'views' => 100000];

        OutlierVideo::create($base + ['platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'ytShortFlag', 'title' => 'Flagged short, long duration', 'duration' => 'PT2M50S', 'is_short' => true]);
        OutlierVideo::create($base + ['platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'ytLongFlag', 'title' => 'Flagged long, short duration', 'duration' => 'PT45S', 'is_short' => false]);
        OutlierVideo::create($base + ['platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'ytNullLong', 'title' => 'Unclassified 20 min', 'duration' => 'PT20M', 'is_short' => null]);
        OutlierVideo::create($base + ['platform' => 'tiktok', 'channel_id' => $tt->id, 'youtube_video_id' => 'ttNull', 'title' => 'Unclassified tiktok', 'duration' => 'PT10M', 'is_short' => null]);
        OutlierVideo::create(['platform' => 'instagram', 'channel_id' => $ig->id, 'youtube_video_id' => 'igNull', 'title' => 'Unclassified reel', 'thumbnail_url' => 'x', 'published_at' => now(), 'views' => null, 'outlier_score' => null, 'is_short' => null]);
    }

    private function browseIds(string $durationType): array
    {
        return collect($this->withHeaders($this->authHeaders())
            ->getJson("/api/outliers?duration_type={$durationType}&per_page=100")
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id')->all();
    }

    public function test_shorts_tab_is_driven_by_the_classification_not_duration(): void
    {
        $this->seedFormatRows();

        $ids = $this->browseIds('shorts');

        $this->assertContains('ytShortFlag', $ids);      // 2m50 but YouTube says Short
        $this->assertNotContains('ytLongFlag', $ids);    // 45s but YouTube says long-form
        $this->assertContains('ttNull', $ids);           // unclassified TikTok → always short
        $this->assertContains('igNull', $ids);           // unclassified Instagram → always short
        $this->assertNotContains('ytNullLong', $ids);
    }

    public function test_long_tab_is_driven_by_the_classification_not_duration(): void
    {
        $this->seedFormatRows();

        $ids = $this->browseIds('long');

        $this->assertContains('ytLongFlag', $ids);
        $this->assertContains('ytNullLong', $ids);        // unclassified YouTube falls back to long
        $this->assertNotContains('ytShortFlag', $ids);
        $this->assertNotContains('ttNull', $ids);
        $this->assertNotContains('igNull', $ids);
    }

    public function test_browse_payload_exposes_is_short(): void
    {
        $this->seedFormatRows();

        $row = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?duration_type=shorts&per_page=100')
            ->json('data'))->firstWhere('youtube_video_id', 'ytShortFlag');

        $this->assertTrue($row['is_short']);
    }

    public function test_manually_added_video_shows_in_browse_despite_low_score(): void
    {
        $this->seedMixedPlatforms();
        $yt = OutlierChannel::where('youtube_channel_id', 'UC1')->first();
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'manual1',
            'title' => 'Manually added', 'thumbnail_url' => 'x', 'views' => 100,
            'outlier_score' => 0.4, 'published_at' => now(), 'manually_added' => true,
        ]);

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?min_score=20')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertContains('manual1', $ids);   // manual add bypasses the gate
        $this->assertContains('yt1', $ids);       // organic outlier still shows
        $this->assertContains('igshort1', $ids);  // IG rows still exempt
    }

    public function test_fetch_by_url_rejects_a_non_video_youtube_link(): void
    {
        config()->set('services.youtube.key', 'yt_test_key');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/fetch', ['platform' => 'youtube', 'url' => 'https://www.youtube.com/@somechannel'])
            ->assertStatus(422);
    }

    public function test_channels_endpoint_lists_all_platforms_when_none_given(): void
    {
        $this->seedMixedPlatforms();

        $names = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/channels')
            ->assertOk()
            ->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Grammer', 'Tuber'], $names);
    }

    public function test_channels_endpoint_matches_handle_and_platform_id_not_just_display_name(): void
    {
        OutlierChannel::create(['platform' => 'instagram', 'youtube_channel_id' => 'raycfu', 'handle' => 'raycfu', 'channel_name' => 'Ray Fu', 'subscriber_count' => 10]);
        OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCabc', 'handle' => 'mrbeast', 'channel_name' => 'MrBeast', 'subscriber_count' => 20]);
        OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => '999', 'channel_name' => 'Nobody', 'subscriber_count' => 5]);

        $names = fn (string $q) => collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers/channels?q='.urlencode($q))
            ->assertOk()
            ->json('data'))->pluck('name')->all();

        $this->assertSame(['Ray Fu'], $names('raycfu'), 'matches the stored handle');
        $this->assertSame(['Ray Fu'], $names('@raycfu'), 'a leading @ is ignored');
        $this->assertSame(['MrBeast'], $names('UCabc'), 'matches the platform-native id');
        $this->assertSame(['MrBeast'], $names('beast'), 'still matches the display name');
        $this->assertSame([], $names('zzz'));
    }

    public function test_search_results_match_only_the_full_phrase_not_individual_words(): void
    {
        $yt = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC9', 'channel_name' => 'Maker', 'subscriber_count' => 1000]);
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'rel1',
            'title' => 'Best AI app builder', 'thumbnail_url' => 'x', 'views' => 50000,
            'outlier_score' => 40, 'published_at' => now(),
        ]);
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'noise1',
            'title' => 'AI news roundup', 'thumbnail_url' => 'x', 'views' => 90000,
            'outlier_score' => 80, 'published_at' => now(),
        ]);

        // Full phrase term → the relevant video; the single word "ai" → the noise video.
        $phrase = \App\Models\SearchTerm::create(['term' => 'ai app builder']);
        \App\Models\TermsDataFetch::create(['term_id' => $phrase->id, 'status' => \App\Models\TermsDataFetch::STATUS_DONE]);
        \App\Models\SearchResult::create(['term_id' => $phrase->id, 'video_youtube_id' => 'rel1']);

        $word = \App\Models\SearchTerm::create(['term' => 'ai']);
        \App\Models\TermsDataFetch::create(['term_id' => $word->id, 'status' => \App\Models\TermsDataFetch::STATUS_DONE]);
        \App\Models\SearchResult::create(['term_id' => $word->id, 'video_youtube_id' => 'noise1']);

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?query=ai+app+builder')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertContains('rel1', $ids);
        $this->assertNotContains('noise1', $ids); // "ai"-only results must not bleed in
    }

    public function test_search_includes_db_title_matches_outside_the_scraped_set(): void
    {
        $yt = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC8', 'channel_name' => 'Marketer', 'subscriber_count' => 1000]);
        $base = ['platform' => 'youtube', 'channel_id' => $yt->id, 'thumbnail_url' => 'x', 'views' => 50000, 'outlier_score' => 40, 'published_at' => now()];

        // In the DB from an earlier scrape/URL-add, but NOT in this term's SearchResult set.
        OutlierVideo::create($base + ['youtube_video_id' => 'oldSeo', 'title' => 'SEO tips for 2024']);
        OutlierVideo::create($base + ['youtube_video_id' => 'pasta', 'title' => 'Cooking pasta at home']);
        // The one video the current scrape recorded for the term.
        OutlierVideo::create($base + ['youtube_video_id' => 'newSeo', 'title' => 'Best SEO hacks']);

        $term = \App\Models\SearchTerm::create(['term' => 'seo']);
        \App\Models\TermsDataFetch::create(['term_id' => $term->id, 'status' => \App\Models\TermsDataFetch::STATUS_DONE]);
        \App\Models\SearchResult::create(['term_id' => $term->id, 'video_youtube_id' => 'newSeo']);

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?query=seo')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertContains('newSeo', $ids);   // scraped result
        $this->assertContains('oldSeo', $ids);   // DB-first: title match survives scrape churn
        $this->assertNotContains('pasta', $ids); // unrelated titles stay out
    }

    public function test_search_returns_db_title_matches_before_the_term_is_ever_scraped(): void
    {
        $yt = OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UC7', 'channel_name' => 'Marketer', 'subscriber_count' => 1000]);
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'oldSeo',
            'title' => 'SEO tips for 2024', 'thumbnail_url' => 'x', 'views' => 50000,
            'outlier_score' => 40, 'published_at' => now(),
        ]);

        // No SearchTerm row for "seo" yet — the scrape hasn't even been queued.
        $res = $this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?query=seo')
            ->assertOk();

        $this->assertSame('queued', $res->json('status'));
        $this->assertContains('oldSeo', collect($res->json('data'))->pluck('youtube_video_id'));
    }

    public function test_featured_filter_returns_only_featured_and_skips_score_gate(): void
    {
        $this->seedMixedPlatforms();
        $yt = OutlierChannel::where('youtube_channel_id', 'UC1')->first();
        OutlierVideo::create([
            'platform' => 'youtube', 'channel_id' => $yt->id, 'youtube_video_id' => 'feat1',
            'title' => 'Curated pick', 'thumbnail_url' => 'x', 'views' => 500,
            'outlier_score' => 2, 'published_at' => now(), 'featured' => true,
        ]);

        $ids = collect($this->withHeaders($this->authHeaders())
            ->getJson('/api/outliers?featured=1&min_score=20')
            ->assertOk()
            ->json('data'))->pluck('youtube_video_id');

        $this->assertSame(['feat1'], $ids->all()); // low score, still shown; others excluded
    }

    public function test_admin_can_toggle_featured_and_non_admin_cannot(): void
    {
        $this->seedMixedPlatforms();

        $admin = User::factory()->create();
        $admin->roles()->attach(\App\Models\Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Admin'])->id);
        $adminToken = $this->postJson('/api/login', ['email' => $admin->email, 'password' => 'password'])->json('data.token');
        $adminHeaders = ['Authorization' => 'Bearer '.$adminToken, 'Accept' => 'application/json'];

        $this->withHeaders($adminHeaders)
            ->postJson('/api/outliers/youtube/yt1/feature')
            ->assertOk()
            ->assertJsonPath('data.featured', true);

        $this->assertTrue(OutlierVideo::where('youtube_video_id', 'yt1')->first()->featured);

        // Toggle back off
        $this->withHeaders($adminHeaders)
            ->postJson('/api/outliers/youtube/yt1/feature')
            ->assertJsonPath('data.featured', false);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/outliers/youtube/yt1/feature')
            ->assertForbidden();
    }
}
