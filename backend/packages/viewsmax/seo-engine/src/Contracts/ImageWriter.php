<?php

namespace ViewsMax\SeoEngine\Contracts;

use ViewsMax\SeoEngine\Models\SeoArticle;

interface ImageWriter
{
    /**
     * Generate and attach images to the article: a featured (hero) image
     * stored on `featured_image_url`, plus inline <figure> images injected
     * into the body HTML. Persists the article.
     */
    public function illustrate(SeoArticle $article): void;
}
