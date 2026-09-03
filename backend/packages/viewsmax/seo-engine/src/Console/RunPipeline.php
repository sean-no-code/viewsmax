<?php

namespace ViewsMax\SeoEngine\Console;

use Illuminate\Support\Facades\Artisan;

/**
 * The daily heartbeat: keep the keyword well full, draft ahead of the cadence,
 * publish up to the weekly cap, and refresh outreach prospects. Each stage is
 * its own command so a vendor failure in one never blocks the others.
 */
class RunPipeline extends SeoCommand
{
    protected $signature = 'seo:run';

    protected $description = 'Run the full SEO pipeline: discover -> draft -> publish -> prospect.';

    protected function run_(): int
    {
        foreach ([
            'seo:discover-keywords' => [],
            'seo:draft-articles' => [],
            'seo:publish-articles' => [],
            'seo:prospect-backlinks' => [],
        ] as $command => $args) {
            try {
                Artisan::call($command, $args);
                $this->line(trim(Artisan::output()));
            } catch (\Throwable $e) {
                $this->error("{$command} failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
