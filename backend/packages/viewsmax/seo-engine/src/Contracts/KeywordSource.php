<?php

namespace ViewsMax\SeoEngine\Contracts;

use Illuminate\Support\Collection;

interface KeywordSource
{
    /**
     * Mine keywords a competitor domain ranks for.
     *
     * @return Collection<int, array{keyword:string, search_volume:int, difficulty:int, cpc:float, competitor_url:?string}>
     */
    public function rankedKeywords(string $competitorDomain, int $limit = 100): Collection;
}
