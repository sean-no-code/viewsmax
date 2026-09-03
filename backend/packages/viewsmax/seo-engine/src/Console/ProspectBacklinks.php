<?php

namespace ViewsMax\SeoEngine\Console;

use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Services\SeoPipeline;

class ProspectBacklinks extends SeoCommand
{
    protected $signature = 'seo:prospect-backlinks';

    protected $description = 'Store competitor backlink prospects for every enabled SEO profile (DataForSEO).';

    protected function run_(): int
    {
        $pipeline = app(SeoPipeline::class);
        $total = 0;
        foreach (SeoProfile::where('enabled', true)->get() as $profile) {
            if (! $profile->runnable()) {
                continue;
            }
            $total += $pipeline->prospect($profile);
        }
        $this->info("Stored {$total} new prospect(s).");

        return self::SUCCESS;
    }
}
