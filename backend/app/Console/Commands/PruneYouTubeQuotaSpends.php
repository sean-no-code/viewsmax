<?php

namespace App\Console\Commands;

use App\Models\YouTubeQuotaSpend;
use Illuminate\Console\Command;

class PruneYouTubeQuotaSpends extends Command
{
    protected $signature = 'youtube-quota:prune {--days= : Keep this many days of ledger rows (default: YOUTUBE_QUOTA_RETENTION_DAYS)}';

    protected $description = 'Delete YouTube quota ledger rows older than the retention window.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('services.youtube.quota.retention_days', 90));
        $deleted = YouTubeQuotaSpend::query()->where('spent_at', '<', now()->subDays($days))->delete();
        $this->info("Pruned {$deleted} YouTube quota ledger rows older than {$days} days.");

        return self::SUCCESS;
    }
}
