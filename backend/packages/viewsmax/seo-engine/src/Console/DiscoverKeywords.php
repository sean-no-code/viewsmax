<?php

namespace ViewsMax\SeoEngine\Console;

use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Services\KeywordDiscovery;

class DiscoverKeywords extends SeoCommand
{
    protected $signature = 'seo:discover-keywords {--per-competitor=100}';

    protected $description = 'Mine high-intent competitor keywords for every enabled SEO profile (DataForSEO).';

    protected function run_(): int
    {
        $discovery = app(KeywordDiscovery::class);
        $total = 0;
        foreach (SeoProfile::where('enabled', true)->get() as $profile) {
            if (! $profile->runnable()) {
                continue;
            }
            $total += $discovery->run($profile, (int) $this->option('per-competitor'));
        }
        $this->info("Stored {$total} new keyword(s).");

        return self::SUCCESS;
    }
}
