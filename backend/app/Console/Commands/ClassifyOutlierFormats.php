<?php

namespace App\Console\Commands;

use App\Models\OutlierVideo;
use App\Services\Exceptions\ProbeRateLimited;
use App\Services\VideoFormatClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill / catch-up for the Shorts vs long-form classification. Idempotent and
 * resumable: only touches rows with is_short IS NULL, never re-probes recently
 * checked unknowns, and stops at the first rate-limit signal from YouTube.
 */
class ClassifyOutlierFormats extends Command
{
    protected $signature = 'outliers:classify-formats
        {--limit=500 : Max YouTube rows to probe this run}
        {--delay-ms=250 : Pause between probes}
        {--retry-unknown-days=7 : Re-probe inconclusive rows older than this (0 = never)}
        {--dry-run : Report counts only}';

    protected $description = 'Classify outlier videos as Shorts or long-form (TikTok/Instagram by platform, YouTube via its /shorts/ URL).';

    public function handle(VideoFormatClassifier $classifier): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $delayMs = max(0, (int) $this->option('delay-ms'));
        $retryDays = max(0, (int) $this->option('retry-unknown-days'));
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';

        // 1. Platform rule — one statement, no HTTP.
        $platformQuery = OutlierVideo::whereIn('platform', ['tiktok', 'instagram'])->whereNull('is_short');
        $platformCount = $platformQuery->count();
        if (! $dryRun && $platformCount > 0) {
            $platformQuery->update(['is_short' => true, 'format_checked_at' => now()]);
        }

        // 2. YouTube rows never checked, or whose inconclusive check is stale.
        $youtubeQuery = OutlierVideo::where('platform', 'youtube')
            ->whereNull('is_short')
            ->where(function ($q) use ($retryDays) {
                $q->whereNull('format_checked_at');
                if ($retryDays > 0) {
                    $q->orWhere('format_checked_at', '<', now()->subDays($retryDays));
                }
            })
            ->orderBy('id');
        $candidates = $youtubeQuery->count();

        $counts = ['short' => 0, 'long' => 0, 'unknown' => 0];
        $probed = 0;

        if (! $dryRun) {
            $videos = $youtubeQuery->limit($limit)->get();
            foreach ($videos as $i => $video) {
                try {
                    $result = $classifier->ensureClassified($video);
                } catch (ProbeRateLimited $e) {
                    $this->warn("Rate limited after {$probed} probes ({$e->getMessage()}) — stopping; rerun later.");
                    break;
                }
                $probed++;
                $counts[$result === true ? 'short' : ($result === false ? 'long' : 'unknown')]++;
                if ($delayMs > 0 && $i < $videos->count() - 1) {
                    usleep($delayMs * 1000);
                }
            }
        }

        $remaining = OutlierVideo::whereNull('is_short')->count();
        $summary = "{$prefix}Platform-marked {$platformCount}. YouTube: {$candidates} candidates, probed {$probed} → "
            ."short {$counts['short']}, long {$counts['long']}, unknown {$counts['unknown']}. Remaining unclassified: {$remaining}.";
        $this->info($summary);
        Log::info('outliers:classify-formats ran', compact('platformCount', 'candidates', 'probed', 'counts', 'remaining', 'dryRun'));

        return self::SUCCESS;
    }
}
