<?php

namespace ViewsMax\SeoEngine\Console;

use Illuminate\Console\Command;

/** Shared kill-switch: every seo:* command no-ops when the engine is off. */
abstract class SeoCommand extends Command
{
    public function handle(): int
    {
        if (! config('seo-engine.enabled')) {
            $this->warn('seo-engine is disabled (SEO_ENGINE_ENABLED=false) — nothing to do.');

            return self::SUCCESS;
        }

        return $this->run_();
    }

    abstract protected function run_(): int;
}
