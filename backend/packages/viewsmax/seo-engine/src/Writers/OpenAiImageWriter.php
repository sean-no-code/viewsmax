<?php

namespace ViewsMax\SeoEngine\Writers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ViewsMax\SeoEngine\Contracts\ImageWriter;
use ViewsMax\SeoEngine\Models\SeoArticle;

/**
 * Illustrates articles with the OpenAI Images API (reuses the host app's
 * services.openai credentials): one featured/hero image plus inline section
 * <figure>s injected after evenly-spread H2 headings. Files are stored on the
 * configured public disk; the WordPress publisher sideloads the featured image
 * into the WP media library at publish time.
 */
class OpenAiImageWriter implements ImageWriter
{
    public function illustrate(SeoArticle $article): void
    {
        $generated = 0;
        $firstError = null;

        if (! $article->featured_image_url) {
            try {
                $article->featured_image_url = $this->generate($this->featuredPrompt($article), $article, 'featured');
                $generated++;
            } catch (\Throwable $e) {
                $firstError = $e;
                Log::warning('[seo-engine] featured image failed', ['article_id' => $article->id, 'error' => $e->getMessage()]);
            }
        }

        // Skip inline injection when figures are already present (re-runs,
        // publish-time backfill after a partial failure, hand-edited bodies).
        $per = max(0, (int) config('seo-engine.images.per_article'));
        if ($per > 0 && ! str_contains((string) $article->html, '<figure')) {
            $generated += $this->injectFigures($article, $per);
        }

        $article->save();

        if ($generated === 0 && $firstError) {
            throw $firstError; // nothing worked — surface the cause so a later run retries
        }
    }

    /** Inject up to $count section images after evenly-spread H2 headings. */
    private function injectFigures(SeoArticle $article, int $count): int
    {
        $html = (string) $article->html;
        if (! preg_match_all('/<h2[^>]*>(.*?)<\/h2>/is', $html, $m, PREG_OFFSET_CAPTURE)) {
            return 0;
        }

        $headings = $m[0];
        $total = count($headings);
        $count = min($count, $total);

        // Even spread across the article (e.g. 2 images over 6 headings ->
        // headings 2 and 4), never bunched at the top.
        $picked = [];
        for ($i = 1; $i <= $count; $i++) {
            $picked[(int) floor($i * $total / ($count + 1))] = true;
        }

        $insertions = [];
        foreach (array_keys($picked) as $idx) {
            $alt = trim(strip_tags($m[1][$idx][0]));
            try {
                $url = $this->generate($this->sectionPrompt($article, $alt), $article, 'section'.$idx);
            } catch (\Throwable $e) {
                Log::warning('[seo-engine] section image failed', ['article_id' => $article->id, 'heading' => $alt, 'error' => $e->getMessage()]);

                continue;
            }
            $insertions[] = [
                'pos' => $headings[$idx][1] + strlen($headings[$idx][0]),
                'html' => '<figure><img src="'.e($url).'" alt="'.e($alt).'" style="max-width:100%;height:auto;border-radius:8px;" /></figure>',
            ];
        }

        // Insert bottom-up so earlier offsets stay valid.
        usort($insertions, fn (array $a, array $b) => $b['pos'] <=> $a['pos']);
        foreach ($insertions as $ins) {
            $html = substr($html, 0, $ins['pos']).$ins['html'].substr($html, $ins['pos']);
        }

        $article->html = $html;

        return count($insertions);
    }

    private function featuredPrompt(SeoArticle $article): string
    {
        $keyword = $article->keyword?->keyword;

        return 'Hero image for a blog article titled "'.$article->title.'"'
            .($keyword ? ' targeting the topic "'.$keyword.'"' : '')
            .'. '.config('seo-engine.images.style');
    }

    private function sectionPrompt(SeoArticle $article, string $heading): string
    {
        return 'Illustration for the section "'.$heading.'" of a blog article titled "'.$article->title.'". '
            .config('seo-engine.images.style');
    }

    /** Generate one image, store it on the configured disk, return its public URL. */
    private function generate(string $prompt, SeoArticle $article, string $tag): string
    {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured (services.openai.api_key).');
        }

        $cfg = config('seo-engine.images');
        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->post(config('services.openai.image_api_url', 'https://api.openai.com/v1/images/generations'), [
                'model' => $cfg['model'],
                'prompt' => $prompt,
                'size' => $cfg['size'],
                'quality' => $cfg['quality'],
                'n' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('OpenAI image failed (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 300));
        }

        // gpt-image-1 returns base64; dall-e models return a temporary URL.
        if ($b64 = $response->json('data.0.b64_json')) {
            $bytes = base64_decode($b64);
        } elseif ($url = $response->json('data.0.url')) {
            $download = Http::timeout(60)->get($url);
            if (! $download->successful()) {
                throw new RuntimeException('Could not download generated image (HTTP '.$download->status().').');
            }
            $bytes = $download->body();
        } else {
            throw new RuntimeException('OpenAI image response contained no image data.');
        }

        $path = trim($cfg['path'], '/').'/'.$article->id.'-'.$tag.'-'.Str::lower(Str::random(8)).'.png';
        $disk = Storage::disk($cfg['disk']);
        $disk->put($path, $bytes, 'public');

        return $disk->url($path);
    }
}
