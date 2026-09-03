<?php

namespace ViewsMax\SeoEngine\Publishers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ViewsMax\SeoEngine\Contracts\ArticlePublisher;
use ViewsMax\SeoEngine\Models\SeoArticle;
use ViewsMax\SeoEngine\Models\SeoProfile;
use ViewsMax\SeoEngine\Support\OutboundUrl;

/**
 * Pushes articles to WordPress over the REST API using an application
 * password (Users -> Profile -> Application Passwords in wp-admin). The
 * featured image is sideloaded into the WP media library so themes render it
 * as the post thumbnail / social card.
 */
class WordPressPublisher implements ArticlePublisher
{
    public function publish(SeoArticle $article): array
    {
        // Per-user blog: credentials live on the article's profile, set by the
        // user in the SEO settings UI (not in .env).
        $profile = $article->profile;
        if (! $profile || empty($profile->wp_url) || empty($profile->wp_username) || empty($profile->wp_app_password)) {
            throw new RuntimeException('Connect your WordPress blog in SEO settings (site URL, username and an application password) before publishing.');
        }

        $payload = [
            'title' => $article->title,
            'slug' => $article->slug,
            'content' => $article->html,
            'excerpt' => (string) $article->meta_description,
            'status' => 'publish',
        ];

        // A failed thumbnail should never block the post itself.
        if ($article->featured_image_url) {
            try {
                $payload['featured_media'] = $this->uploadFeaturedMedia($profile, $article);
            } catch (\Throwable $e) {
                Log::warning('[seo-engine] featured media upload failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);
            }
        }

        // wp_url is user-supplied: refuse private/loopback/metadata targets and
        // don't follow redirects, so the worker can't be steered off the blog.
        OutboundUrl::assertPublic($profile->wp_url, 'WordPress site URL');

        $response = Http::withBasicAuth($profile->wp_username, $profile->wp_app_password)
            ->withoutRedirecting()
            ->timeout(60)
            ->post(rtrim($profile->wp_url, '/').'/wp-json/wp/v2/posts', $payload);

        if (! $response->successful()) {
            throw $this->httpFailure('WordPress publish failed', $response->status(), $response->body(), $article);
        }

        return [
            'post_id' => (string) $response->json('id'),
            'url' => $response->json('link'),
        ];
    }

    /** Sideload the featured image into the WP media library; returns the attachment id. */
    private function uploadFeaturedMedia(SeoProfile $profile, SeoArticle $article): int
    {
        OutboundUrl::assertPublic($article->featured_image_url, 'featured image URL');

        $image = Http::withoutRedirecting()->timeout(60)->get($article->featured_image_url);
        if (! $image->successful()) {
            throw new RuntimeException('Could not download the featured image (HTTP '.$image->status().').');
        }

        $mime = $image->header('Content-Type') ?: 'image/png';
        $ext = pathinfo((string) parse_url($article->featured_image_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'png';

        $response = Http::withBasicAuth($profile->wp_username, $profile->wp_app_password)
            ->withoutRedirecting()
            ->timeout(120)
            ->withHeaders(['Content-Disposition' => 'attachment; filename="'.$article->slug.'.'.$ext.'"'])
            ->withBody($image->body(), $mime)
            ->post(rtrim($profile->wp_url, '/').'/wp-json/wp/v2/media');

        if (! $response->successful() || ! $response->json('id')) {
            throw $this->httpFailure('WordPress media upload failed', $response->status(), $response->body(), $article);
        }

        return (int) $response->json('id');
    }

    /**
     * The response body stays in the server log only. It is echoed into
     * seo_articles.error otherwise, which the profile owner can read — turning
     * any reachable host into a response oracle.
     */
    private function httpFailure(string $what, int $status, string $body, SeoArticle $article): RuntimeException
    {
        Log::warning('[seo-engine] '.$what, [
            'article_id' => $article->id,
            'status' => $status,
            'body' => mb_substr($body, 0, 300),
        ]);

        return new RuntimeException("{$what} (HTTP {$status}). Check the WordPress URL and application password in SEO settings.");
    }
}
