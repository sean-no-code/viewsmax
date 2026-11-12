<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Posts for admin@admin.com so the Calendar page has something to show: 30
 * already posted over the last month and 30 scheduled over the next. The
 * posted ones are marked published but never went out (no platform post
 * ids), so there are no metrics behind them. Text posts go to X / LinkedIn /
 * Threads / Bluesky, image posts to Instagram / Facebook / Pinterest / TikTok.
 * Targets use the admin's connected account for a platform when there is one.
 *
 * Idempotent — removes the posts it seeded last time and reseeds, with dates
 * relative to now. Run:
 *   docker exec viewsmax_app php artisan db:seed --class=CalendarPostSeeder
 *
 * If the scheduler runs locally, posts:publish-due picks these up as they
 * fall due; without connected accounts their targets fail.
 */
class CalendarPostSeeder extends Seeder
{
    private const COUNT = 30;
    private const DAYS = 30;
    private const MARKER = 'CalendarPostSeeder';

    private const TEXT_PLATFORMS = ['x', 'linkedin', 'threads', 'bluesky'];
    private const IMAGE_PLATFORMS = ['instagram', 'facebook', 'pinterest', 'tiktok'];

    private const CAPTIONS = [
        'The first 3 seconds decide everything. Here is how we rewrote our hooks and doubled retention.',
        'Stop posting daily. Post when you have something worth watching.',
        'We tested 12 thumbnails on the same video. The winner was the ugliest one.',
        'Your title is a promise. Your video has to keep it in the first 30 seconds.',
        'Behind the scenes of this week\'s shoot — one camera, one light, zero excuses.',
        'Three outlier videos in our niche this month and the one thing they all had in common.',
        'Consistency beats talent when talent doesn\'t show up.',
        'New video drops Thursday. Here\'s a sneak peek at the setup.',
        'Comment "SCRIPT" and I\'ll send you the template we use for every short.',
        'The metric nobody watches that predicts whether a video takes off: returning viewers.',
        'Repurposing one long video into 9 shorts — the exact workflow.',
        'Hot take: most creators don\'t have a content problem, they have a packaging problem.',
        'What 100 days of posting taught us about the algorithm.',
        'Friday Q&A — drop your questions about growing on short-form below.',
        'This one hook formula works in every niche we\'ve tried.',
    ];

    public function run(): void
    {
        if (config('app.env') === 'production') {
            $this->command?->info('CalendarPostSeeder: skipping in production');

            return;
        }

        $user = User::where('email', 'admin@admin.com')->first();
        if (! $user) {
            $this->command?->warn('CalendarPostSeeder: admin@admin.com not found — run UserSeeder first.');

            return;
        }

        $this->wipeExisting($user);

        $accounts = SocialAccount::where('user_id', $user->id)
            ->where('status', SocialAccount::STATUS_CONNECTED)
            ->latest('id')
            ->get()
            ->unique('platform')
            ->keyBy('platform');

        // Fixed seed: the same spread of days, times and platforms every run.
        mt_srand(2026);

        // The first COUNT are already posted over the past DAYS, the rest scheduled over the next DAYS.
        for ($i = 0; $i < self::COUNT * 2; $i++) {
            $past = $i < self::COUNT;
            $n = $i % self::COUNT;
            $isImage = $i % 3 === 0;
            $pool = $isImage ? self::IMAGE_PLATFORMS : self::TEXT_PLATFORMS;
            shuffle($pool);
            $platforms = array_slice($pool, 0, mt_rand(1, 3));

            // Roughly one a day, with some days doubled up and some left empty.
            $day = max(1, min(self::DAYS, intdiv($n * self::DAYS, self::COUNT) + 1 + mt_rand(-1, 1)));
            $scheduledAt = ($past ? now()->startOfDay()->subDays($day) : now()->startOfDay()->addDays($day))
                ->setTime(mt_rand(8, 19), [0, 15, 30, 45][mt_rand(0, 3)]);

            // Without events: a posted post would bump posts_published_count on every run.
            $post = Post::withoutEvents(fn () => Post::create([
                'user_id' => $user->id,
                'caption' => self::CAPTIONS[$i % count(self::CAPTIONS)],
                'media' => $isImage
                    ? [['type' => 'image', 'url' => 'https://picsum.photos/seed/vm' . $i . '/1080/1350']]
                    : [],
                'status' => $past ? Post::STATUS_POSTED : Post::STATUS_SCHEDULED,
                'scheduled_at' => $scheduledAt,
            ]));

            foreach ($platforms as $platform) {
                PostTarget::create([
                    'post_id' => $post->id,
                    'platform' => $platform,
                    'social_account_id' => $accounts->get($platform)?->id,
                    'status' => $past ? PostTarget::STATUS_PUBLISHED : PostTarget::STATUS_PENDING,
                    'published_at' => $past ? $scheduledAt : null,
                    'options' => $platform === 'tiktok' ? ['privacy_level' => 'SELF_ONLY'] : null,
                    'meta' => ['seeded_by' => self::MARKER],
                ]);
            }
        }

        $this->command?->info('CalendarPostSeeder: ' . self::COUNT . ' posted over the last ' . self::DAYS . ' days + ' . self::COUNT . ' scheduled over the next ' . self::DAYS . " days for {$user->email}");
    }

    /** Remove posts from a previous run, found by the marker on their targets. */
    private function wipeExisting(User $user): void
    {
        Post::where('user_id', $user->id)
            ->whereHas('targets', fn ($q) => $q->where('meta->seeded_by', self::MARKER))
            ->delete();
    }
}
