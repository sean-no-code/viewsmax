<?php

namespace ViewsMax\SeoEngine\Contracts;

use ViewsMax\SeoEngine\Models\SeoKeyword;

interface ArticleWriter
{
    /**
     * Draft a full article targeting the keyword.
     *
     * @return array{title:string, slug:string, meta_description:string, html:string}
     */
    public function draft(SeoKeyword $keyword): array;
}
