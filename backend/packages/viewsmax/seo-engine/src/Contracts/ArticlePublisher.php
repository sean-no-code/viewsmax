<?php

namespace ViewsMax\SeoEngine\Contracts;

use ViewsMax\SeoEngine\Models\SeoArticle;

interface ArticlePublisher
{
    /**
     * Push the article live.
     *
     * @return array{post_id:string, url:?string}
     */
    public function publish(SeoArticle $article): array;
}
