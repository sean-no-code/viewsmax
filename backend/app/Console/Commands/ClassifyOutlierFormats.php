<?php

namespace App\Console\Commands;

use App\Models\OutlierVideo;
use App\Services\VideoFormatClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill / catch-up for the Shorts vs long-form classification. Idempotent and
 * resumable: only touches rows with is_short IS NULL and never re-checks
 * recently inspected unknowns (rows still missing a duration).
 */
class ClassifyOutlierFormats extends Command
{
    protected $signature = 'outliers:classify-formats
        {--limit=500 : Max YouTube rows to classify this run}
        {--retry-unknown-days=7 : Re-check inconclusive rows older than this (0 = never)}
        {--dry-run : Report counts only}';

    protected $description = 'Classify outlier videos as Shorts or long-form (TikTok/Instagram by platform, YouTube by duration).';

    public function handle(VideoFormatClassifier $classifier): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $retryDays = max(0, (int) $this->option('retry-unknown-days'));
        $dryRun = (bool) $this->option('dry-run');
        $prefix = $dryRun ? '[dry-run] ' : '';

        // 1. Platform rule — one statement.
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
        $classified = 0;

        if (! $dryRun) {
            foreach ($youtubeQuery->limit($limit)->get() as $video) {
                $result = $classifier->ensureClassified($video);
                $classified++;
                $counts[$result === true ? 'short' : ($result === false ? 'long' : 'unknown')]++;
            }
        }

        $remaining = OutlierVideo::whereNull('is_short')->count();
        $summary = "{$prefix}Platform-marked {$platformCount}. YouTube: {$candidates} candidates, classified {$classified} → "
            ."short {$counts['short']}, long {$counts['long']}, unknown {$counts['unknown']}. Remaining unclassified: {$remaining}.";
        $this->info($summary);
        Log::info('outliers:classify-formats ran', compact('platformCount', 'candidates', 'classified', 'counts', 'remaining', 'dryRun'));

        return self::SUCCESS;
    }
}
