<?php

namespace Tests\Feature;

use App\Models\OutlierChannel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackfillOutlierChannelCountriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_fills_missing_youtube_channel_countries(): void
    {
        config()->set('services.youtube.key', 'yt_test_key');

        OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCa', 'channel_name' => 'A']);
        OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCb', 'channel_name' => 'B']);
        // Already has a country → must not be re-fetched or overwritten.
        OutlierChannel::create(['platform' => 'youtube', 'youtube_channel_id' => 'UCc', 'channel_name' => 'C', 'country' => 'DE']);
        // Non-YouTube platforms have no channels API → skipped.
        OutlierChannel::create(['platform' => 'tiktok', 'youtube_channel_id' => 'tt1', 'channel_name' => 'T']);

        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [
                ['id' => 'UCa', 'snippet' => ['title' => 'A', 'country' => 'us']],
                // UCb: YouTube returns no country for this channel — stays NULL.
                ['id' => 'UCb', 'snippet' => ['title' => 'B']],
            ]], 200),
        ]);

        $this->artisan('outliers:backfill-channel-countries')->assertSuccessful();

        $this->assertSame('US', OutlierChannel::where('youtube_channel_id', 'UCa')->first()->country);
        $this->assertNull(OutlierChannel::where('youtube_channel_id', 'UCb')->first()->country);
        $this->assertSame('DE', OutlierChannel::where('youtube_channel_id', 'UCc')->first()->country);
        $this->assertNull(OutlierChannel::where('youtube_channel_id', 'tt1')->first()->country);

        // Only the two NULL-country YouTube channels go to the API, in one batch.
        Http::assertSentCount(1);
    }
}
