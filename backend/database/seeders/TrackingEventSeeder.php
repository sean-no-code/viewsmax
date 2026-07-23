<?php

namespace Database\Seeders;

use App\Models\Offer;
use App\Models\TrackingClick;
use App\Models\TrackingConversion;
use App\Models\TrackingGoal;
use App\Models\TrackingLink;
use App\Models\TrackingVisitor;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class TrackingEventSeeder extends Seeder
{
    /**
     * Seed a handful of realistic offers, each with varied links + simulated
     * traffic, so the Offers / links analytics views populate well.
     */
    public function run(): void
    {
        $user = User::first();

        if (!$user) {
            $this->command->info('No users found. Skipping TrackingEventSeeder.');
            return;
        }

        $video = $this->resolveVideo($user);

        // A few distinct offers so the Offers list is populated.
        $offerBlueprints = [
            ['name' => 'Spring Launch', 'url' => 'https://seannocode.com'],
            ['name' => 'Free Workshop', 'url' => 'https://seannocode.com/workshop'],
            ['name' => 'Newsletter Funnel', 'url' => 'https://seannocode.com/newsletter'],
        ];

        // Placements to spread across links so the platform rollups look real.
        $placements = [
            TrackingLink::PLACEMENT_VIDEO,
            TrackingLink::PLACEMENT_EMAIL,
            TrackingLink::PLACEMENT_X,
            TrackingLink::PLACEMENT_INSTAGRAM,
            TrackingLink::PLACEMENT_TIKTOK,
            TrackingLink::PLACEMENT_LINKEDIN,
            TrackingLink::PLACEMENT_BLOG,
            TrackingLink::PLACEMENT_WEBSITE,
        ];

        foreach ($offerBlueprints as $blueprint) {
            $offer = Offer::create([
                'user_id' => $user->id,
                'name' => $blueprint['name'],
                'offer_url' => $blueprint['url'],
                'conversion_value' => 0,
                'created_at' => now()->subDays(rand(7, 30)),
            ]);

            // Conversion goals per offer.
            foreach ([
                [Offer::TYPE_CONVERSION, '/thank-you', 29.97],
                [Offer::TYPE_CALL_BOOKED, '/call-booked', 50.00],
                [Offer::TYPE_EMAIL_SIGNUP, '/welcome', 5.00],
            ] as [$type, $path, $value]) {
                TrackingGoal::create([
                    'tracking_event_id' => $offer->id,
                    'event_type' => $type,
                    'conversion_url' => $blueprint['url'] . $path,
                    'conversion_value' => $value,
                ]);
            }

            // 4–6 links per offer with varied placements.
            $linkCount = rand(4, 6);
            for ($i = 0; $i < $linkCount; $i++) {
                $placement = $placements[($i + $offer->id) % count($placements)];
                $isVideo = $placement === TrackingLink::PLACEMENT_VIDEO && $video;

                $simulatedViews = rand(200, 8000);
                $initialViewCount = 0;
                if ($isVideo) {
                    if ($video->view_count < $simulatedViews) {
                        $video->update(['view_count' => $simulatedViews + rand(100, 500)]);
                    }
                    $initialViewCount = max(0, $video->view_count - $simulatedViews);
                }

                $link = TrackingLink::create([
                    'tracking_event_id' => $offer->id,
                    'video_id' => $isVideo ? $video->id : null,
                    'placement' => $placement,
                    'name' => ucfirst($placement) . ' — ' . $blueprint['name'] . ' #' . ($i + 1),
                    'parameter_id' => Str::random(10),
                    'initial_view_count' => $initialViewCount,
                    'description' => 'Seeded link',
                ]);

                // CTR 2–3% of views → clicks; conversion 2–4% of clicks.
                $clickCount = max(8, (int) ceil($simulatedViews * (rand(20, 30) / 1000)));
                $this->simulateTrafficForLink($link, $offer, $clickCount, rand(2, 4));
            }
        }

        $this->command->info('Seeded ' . count($offerBlueprints) . ' offers with varied links + traffic.');
    }

    private function simulateTrafficForLink(TrackingLink $link, Offer $offer, int $visitorCount, int $conversionRatePct): void
    {
        for ($j = 0; $j < $visitorCount; $j++) {
            $visitor = TrackingVisitor::create([
                'visitor_id' => Str::uuid(),
                'ip_address' => '192.168.1.' . rand(1, 255),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ]);

            // Spread clicks across the last 7 days (some today) so the trend
            // sparkline + "Last click" column render with recent activity.
            $clickedAt = now()->subDays(rand(0, 6))->subMinutes(rand(0, 1440));

            TrackingClick::create([
                'tracking_visitor_id' => $visitor->id,
                'tracking_link_id' => $link->id,
                'created_at' => $clickedAt,
            ]);

            if (rand(1, 100) <= $conversionRatePct) {
                $type = $this->getRandomConversionType();
                $value = match ($type) {
                    Offer::TYPE_SALE => 4997.00,
                    Offer::TYPE_CONVERSION => 29.00,
                    Offer::TYPE_CALL_BOOKED => 50.00,
                    Offer::TYPE_EMAIL_SIGNUP => 5.00,
                    default => 0,
                };

                TrackingConversion::create([
                    'tracking_visitor_id' => $visitor->id,
                    'tracking_event_id' => $offer->id,
                    'event_type' => $type,
                    'value' => $value,
                    'created_at' => $clickedAt->copy()->addMinutes(rand(1, 120)),
                ]);
            }
        }
    }

    private function resolveVideo(User $user)
    {
        $video = \App\Models\Video::first();
        if ($video) {
            return $video;
        }

        $channel = \App\Models\Channel::first() ?? \App\Models\Channel::create([
            'user_id' => $user->id,
            'youtube_channel_id' => 'UC' . Str::random(22),
            'channel_name' => 'Seeded Channel',
            'subscriber_count' => 1000,
            'video_count' => 1,
            'view_count' => 5000,
        ]);

        return \App\Models\Video::create([
            'channel_id' => $channel->id,
            'title' => 'Seeded Video',
            'youtube_video_id' => 'dQw4w9WgXcQ',
            'view_count' => 1000,
            'like_count' => 50,
            'comment_count' => 10,
            'published_at' => now(),
        ]);
    }

    private function getRandomConversionType(): string
    {
        $types = [
            Offer::TYPE_CONVERSION,
            Offer::TYPE_CALL_BOOKED,
            Offer::TYPE_EMAIL_SIGNUP,
            Offer::TYPE_SALE,
        ];
        return $types[array_rand($types)];
    }
}
