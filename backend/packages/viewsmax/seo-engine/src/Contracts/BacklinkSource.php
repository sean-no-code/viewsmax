<?php

namespace ViewsMax\SeoEngine\Contracts;

use Illuminate\Support\Collection;

interface BacklinkSource
{
    /**
     * Pages that link to a competitor — each one is a prospect we can pitch.
     *
     * @return Collection<int, array{url:string, domain:string, competitor_url:?string, anchor:?string, domain_rank:int, dofollow:bool}>
     */
    public function linksTo(string $competitorDomain, int $limit = 25): Collection;
}
