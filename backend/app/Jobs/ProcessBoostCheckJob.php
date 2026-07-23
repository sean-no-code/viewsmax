<?php

namespace App\Jobs;

use App\Models\BoostCheck;
use App\Models\BoostSetting;
use App\Models\SocialAccount;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs one due Boost check: read the live setting (edits apply to in-flight
 * checks), fetch the tweet's like count, and either fire the boost action
 * (retweet / promo reply), reschedule 6h out, or exhaust after the last run.
 */
class ProcessBoostCheckJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 120;

    public $tries = 1;

    public function __construct(public int $boostCheckId) {}

    public function handle(SocialProviderManager $manager): void
    {
        $check = BoostCheck::with('target', 'setting.socialAccount')->find($this->boostCheckId);
        if (! $check || $check->status !== BoostCheck::STATUS_PENDING) {
            return;
        }

        $setting = $check->setting;
        $target = $check->target;

        // Setting turned off / deleted, or the post is gone → nothing to do, ever.
        if (! $setting || ! $setting->enabled || ! $target || ! $target->platform_post_id) {
            $check->forceFill([
                'status' => BoostCheck::STATUS_EXHAUSTED,
                'error' => 'Boost setting was disabled before the check ran.',
            ])->save();

            return;
        }

        $account = $setting->socialAccount;
        if (! $account) {
            $check->forceFill([
                'status' => BoostCheck::STATUS_FAILED,
                'error' => 'X account disconnected.',
            ])->save();

            return;
        }

        try {
            /** @var XProvider $provider */
            $provider = $manager->for('x');
            $account = $provider->ensureFreshToken($account);
            if ($account->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                $check->forceFill([
                    'status' => BoostCheck::STATUS_FAILED,
                    'error' => 'X authorization expired — reconnect the account.',
                ])->save();

                return;
            }

            // Boost actions anchor to the ROOT tweet (threads included).
            $rootId = (string) $target->platform_post_id;
            $metrics = $provider->getTweetMetrics($account, [$rootId]);
            $likes = (int) data_get($metrics, "{$rootId}.like_count", 0);

            if ($likes >= $setting->likes_threshold) {
                $this->fire($check, $setting, $provider, $account, $rootId);

                return;
            }

            // Below threshold: burn a run, retry in 6h, or give up.
            $runs = $check->runs_completed + 1;
            $check->forceFill($runs >= BoostCheck::MAX_RUNS ? [
                'runs_completed' => $runs,
                'status' => BoostCheck::STATUS_EXHAUSTED,
            ] : [
                'runs_completed' => $runs,
                'next_run_at' => now()->addHours(BoostCheck::RUN_INTERVAL_HOURS),
            ])->save();
        } catch (\Throwable $e) {
            Log::error('Boost check error', [
                'boost_check_id' => $check->id,
                'feature' => $check->feature,
                'error' => $e->getMessage(),
            ]);
            $check->forceFill([
                'status' => BoostCheck::STATUS_FAILED,
                'error' => $e->getMessage(),
            ])->save();
        }
    }

    private function fire(BoostCheck $check, BoostSetting $setting, XProvider $provider, SocialAccount $account, string $rootId): void
    {
        if ($setting->feature === BoostSetting::FEATURE_AUTO_REPOST) {
            $result = $provider->retweet($account, $rootId);
            $check->forceFill($result['success'] ? [
                'status' => BoostCheck::STATUS_TRIGGERED,
                'result_remote_id' => $rootId,
                'error' => null,
            ] : [
                'status' => BoostCheck::STATUS_FAILED,
                'error' => $result['error'],
            ])->save();

            return;
        }

        $comment = $provider->comment($account, $rootId, (string) $setting->promo_text);
        $check->forceFill($comment->success ? [
            'status' => BoostCheck::STATUS_TRIGGERED,
            'result_remote_id' => $comment->remoteCommentId,
            'error' => null,
        ] : [
            'status' => BoostCheck::STATUS_FAILED,
            'error' => $comment->error,
        ])->save();
    }
}
