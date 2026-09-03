<?php

namespace ViewsMax\SeoEngine\Console;

use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Services\SeoPipeline;

class DraftArticles extends SeoCommand
{
    protected $signature = 'seo:draft-articles {--limit=0 : Per-profile cap; 0 = each profile\'s articles_per_week}';

    protected $description = 'Draft articles for each enabled profile\'s highest-intent keywords (Anthropic).';

    protected function run_(): int
    {
        $pipeline = app(SeoPipeline::class);
        $override = (int) $this->option('limit');
        $total = 0;
        foreach (SeoProfile::where('enabled', true)->get() as $profile) {
            if (! $profile->runnable()) {
                continue;
            }
            $total += $pipeline->draft($profile, $override > 0 ? $override : (int) $profile->articles_per_week);
        }
        $this->info("Drafted {$total} article(s).");

        return self::SUCCESS;
    }
}
