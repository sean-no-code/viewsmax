<?php

namespace ViewsMax\SeoEngine\Console;

use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Services\SeoPipeline;

class PublishArticles extends SeoCommand
{
    protected $signature = 'seo:publish-articles';

    protected $description = 'Publish each enabled profile\'s queued articles to its WordPress blog (per-profile weekly cap).';

    protected function run_(): int
    {
        $pipeline = app(SeoPipeline::class);
        $total = 0;
        foreach (SeoProfile::where('enabled', true)->get() as $profile) {
            $total += $pipeline->publish($profile);
        }
        $this->info("Published {$total} article(s).");

        return self::SUCCESS;
    }
}
