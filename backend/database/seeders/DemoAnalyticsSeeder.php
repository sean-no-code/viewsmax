<?php

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingGoal;
use App\Models\TrackingLink;
use App\Models\TrackingReachSnapshot;
use App\Models\TrackingVisitor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Demo analytics data for admin@testaccount.com: a handful of offers with
 * YouTube-backed + non-YouTube tracking links, 30 days of reach snapshots
 * (so the views chart line has data), and backdated clicks + conversions so
 * the reach/click conversion rates and admin tables render real numbers.
 *
 * Idempotent — wipes this user's existing tracking data and reseeds. Run:
 *   docker exec viewsmax_app php artisan db:seed --class=DemoAnalyticsSeeder
 */
class DemoAnalyticsSeeder extends Seeder
{
    private const DAYS = 30;

    public function run(): void
    {
        $user = User::where('email', 'admin@testaccount.com')->first();
        if (! $user) {
            $this->command?->warn('DemoAnalyticsSeeder: admin@testaccount.com not found — run UserSeeder first.');

            return;
        }

        $this->wipeExisting($user);

        // [offer name, url, sale value, links...]. A link with `yt` set is a
        // YouTube-backed link with reach; `initial`/`gained` drive the view delta.
        $offers = [
            ['Creator Accelerator', 'https://example.com/accelerator', 199, [
                ['YouTube — flagship review', 'video', 'dQw4w9WgXcQ', 62000, 24000, 14],
                ['YouTube — Shorts teaser', 'video', 'shorts_A1B2C3', 15000, 11000, 9],
                ['Instagram bio link', 'instagram', null, 0, 0, 6],
                ['Newsletter feature', 'email', null, 0, 0, 4],
            ]],
            ['Thumbnail Masterclass', 'https://example.com/thumbnails', 79, [
                ['YouTube — tutorial', 'video', 'thumb_Xy9Z', 41000, 9000, 8],
                ['TikTok promo', 'tiktok', null, 0, 0, 7],
                ['Blog embed', 'blog', null, 0, 0, 3],
            ]],
            ['Channel Audit (call)', 'https://example.com/audit', 0, [
                ['YouTube — pinned comment', 'video', 'audit_QwErT', 88000, 30000, 10],
                ['X / Twitter thread', 'x', null, 0, 0, 5],
                ['LinkedIn post', 'linkedin', null, 0, 0, 4],
            ]],
            ['Pro Membership', 'https://example.com/pro', 39, [
                ['YouTube — end screen', 'video', 'pro_MnBv', 27000, 7000, 9],
                ['Website footer', 'website', null, 0, 0, 5],
            ]],
        ];

        foreach ($offers as [$name, $url, $value, $links]) {
            $offer = Offer::create([
                'user_id' => $user->id,
                'name' => $name,
                'offer_url' => $url,
                'conversion_value' => $value,
            ]);
            TrackingGoal::create(['tracking_event_id' => $offer->id, 'event_type' => $value > 0 ? 'sale' : 'call booked', 'conversion_url' => '/thank-you', 'conversion_value' => $value]);
            TrackingGoal::create(['tracking_event_id' => $offer->id, 'event_type' => 'email-signup', 'conversion_url' => '/subscribed', 'conversion_value' => 0]);

            foreach ($links as [$linkName, $placement, $yt, $initial, $gained, $dailyClicks]) {
                $link = TrackingLink::create([
                    'tracking_event_id' => $offer->id,
                    'youtube_video_id' => $yt,
                    'placement' => $placement,
                    'name' => $linkName,
                    'parameter_id' => $this->uniqueHash(),
                    'initial_view_count' => $initial,
                    'current_view_count' => $yt ? $initial + $gained : null,
                    'reach_synced_at' => $yt ? now() : null,
                ]);

                if ($yt) {
                    $this->seedSnapshots($link->id, $initial, $gained);
                }

                $this->seedTraffic($offer, $link, $placement, $value, $dailyClicks);
            }
        }

        $this->command?->info('DemoAnalyticsSeeder: seeded demo analytics for admin@testaccount.com.');
    }

    private function wipeExisting(User $user): void
    {
        $offerIds = Offer::withTrashed()->where('user_id', $user->id)->pluck('id');
        if ($offerIds->isEmpty()) {
            return;
        }
        $linkIds = TrackingLink::whereIn('tracking_event_id', $offerIds)->pluck('id');
        TrackingReachSnapshot::whereIn('tracking_link_id', $linkIds)->delete();
        TrackingConversion::whereIn('tracking_event_id', $offerIds)->delete();
        TrackingClick::whereIn('tracking_link_id', $linkIds)->delete();
        TrackingLink::whereIn('tracking_event_id', $offerIds)->delete();
        TrackingGoal::whereIn('tracking_event_id', $offerIds)->delete();
        Offer::withTrashed()->whereIn('id', $offerIds)->forceDelete();
    }

    /** Cumulative daily view counts growing from `initial` to `initial+gained`. */
    private function seedSnapshots(int $linkId, int $initial, int $gained): void
    {
        for ($d = self::DAYS; $d >= 0; $d--) {
            $frac = (self::DAYS - $d) / self::DAYS;
            TrackingReachSnapshot::create([
                'tracking_link_id' => $linkId,
                'snapshot_date' => now()->subDays($d)->toDateString(),
                'view_count' => (int) round($initial + $gained * $frac),
            ]);
        }
    }

    /** Backdated clicks (+ some conversions) spread across the last 30 days. */
    private function seedTraffic(Offer $offer, TrackingLink $link, string $placement, int $saleValue, int $dailyClicks): void
    {
        for ($d = self::DAYS - 1; $d >= 0; $d--) {
            $count = max(0, $dailyClicks + rand(-2, 3));
            for ($i = 0; $i < $count; $i++) {
                $clickedAt = now()->subDays($d)->startOfDay()->addMinutes(rand(0, 1439));
                $visitor = TrackingVisitor::create([
                    'visitor_id' => (string) Str::uuid(),
                    'ip_address' => '203.0.113.'.rand(1, 254),
                    'user_agent' => 'Mozilla/5.0 (demo)',
                ]);
                $click = TrackingClick::create([
                    'tracking_visitor_id' => $visitor->id,
                    'tracking_link_id' => $link->id,
                    'inferred_platform' => $placement,
                    'created_at' => $clickedAt,
                ]);

                // ~5% of clicks convert.
                if (rand(1, 100) <= 5) {
                    $isSale = $saleValue > 0 && rand(1, 100) <= 55;
                    TrackingConversion::create([
                        'tracking_visitor_id' => $visitor->id,
                        'tracking_event_id' => $offer->id,
                        'tracking_link_id' => $link->id,
                        'tracking_click_id' => $click->id,
                        'event_type' => $isSale ? ($saleValue > 0 ? 'sale' : 'call booked') : 'email-signup',
                        'value' => $isSale ? $saleValue : 0,
                        'created_at' => (clone $clickedAt)->addMinutes(rand(1, 180)),
                    ]);
                }
            }
        }
    }

    private function uniqueHash(): string
    {
        do {
            $hash = Str::lower(Str::random(6));
        } while (TrackingLink::where('parameter_id', $hash)->exists());

        return $hash;
    }
}
