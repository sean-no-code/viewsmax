<?php

namespace ViewsMax\SeoEngine\Writers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use ViewsMax\SeoEngine\Contracts\ArticleWriter;
use ViewsMax\SeoEngine\Models\SeoKeyword;

/**
 * Drafts SEO articles with the Anthropic Messages API (reuses the host app's
 * services.anthropic credentials). The prompt demands strict JSON so the
 * pipeline can persist title/slug/meta/html without human parsing.
 */
class AnthropicArticleWriter implements ArticleWriter
{
    public function draft(SeoKeyword $keyword): array
    {
        $apiKey = config('services.anthropic.api_key');
        if (! $apiKey) {
            throw new RuntimeException('Anthropic API key is not configured (services.anthropic.api_key).');
        }

        // The article promotes the profile's OFFER — its name/URL are the site
        // identity the piece links to.
        $offer = $keyword->profile?->offer;
        $siteName = $offer?->name ?: 'our product';
        $siteUrl = $offer?->offer_url ?: '';
        $minWords = config('seo-engine.writer.min_words');
        // Anchor the model to today — without this it dates titles/content from
        // its training data ("… 2024 Comparison" written in 2026).
        $today = now()->format('F j, Y');
        $year = now()->format('Y');

        $prompt = <<<PROMPT
You are the content lead for {$siteName} ({$siteUrl}).
Today's date is {$today}.

Write an SEO article targeting the search keyword: "{$keyword->keyword}"
Searcher intent is commercial — they are comparing or choosing a tool.

Requirements:
- At least {$minWords} words of genuinely useful, specific content (no fluff).
- Any year in the title, slug or body must be {$year} (or later for future plans) — even if the keyword contains an older year, write the current {$year} edition. Don't cite prices/stats as being from earlier years.
- H2/H3 structure, short paragraphs, a comparison table or list where natural.
- Mention {$siteName} where it honestly fits the use case, linking to {$siteUrl} exactly once in the body; never trash competitors with false claims.
- Write in plain, confident language. No "in today's digital landscape" filler.

Return ONLY a JSON object (no markdown fences) with keys:
  "title": SEO title, <= 60 chars, contains the keyword or a close variant
  "slug": url slug, kebab-case
  "meta_description": <= 155 chars
  "category": exactly one of "Guide: Explainer", "Guide: How-to", "List: Round-up", "List: Resources", "List: Examples" — the archetype that best fits the piece
  "html": the full article body as clean HTML (h2/h3/p/ul/table only)
PROMPT;

        $response = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ])->timeout(180)->post(config('services.anthropic.api_url', 'https://api.anthropic.com/v1/messages'), [
            'model' => config('seo-engine.writer.model') ?: config('services.anthropic.model'),
            'max_tokens' => config('seo-engine.writer.max_tokens'),
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Anthropic draft failed (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 300));
        }

        $text = collect($response->json('content', []))->firstWhere('type', 'text')['text'] ?? '';
        $data = $this->parseJson($text);

        return [
            'title' => (string) ($data['title'] ?? Str::title($keyword->keyword)),
            'slug' => Str::slug($data['slug'] ?? $data['title'] ?? $keyword->keyword),
            'meta_description' => mb_substr((string) ($data['meta_description'] ?? ''), 0, 320),
            'category' => in_array($data['category'] ?? null, \ViewsMax\SeoEngine\Models\SeoArticle::CATEGORIES, true)
                ? $data['category']
                : self::classify($keyword->keyword),
            'html' => (string) ($data['html'] ?? ''),
        ];
    }

    /**
     * Fallback archetype classification from the keyword's shape, for when the
     * model omits/typos the category.
     */
    public static function classify(string $keyword): string
    {
        $kw = mb_strtolower($keyword);

        return match (true) {
            str_contains($kw, 'how to') || str_contains($kw, 'how do') || str_contains($kw, 'how can') => 'Guide: How-to',
            str_contains($kw, 'example') => 'List: Examples',
            str_contains($kw, 'resource') || str_contains($kw, 'tools for') || str_contains($kw, 'ai tools') => 'List: Resources',
            str_contains($kw, 'best ') || str_contains($kw, 'top ') || str_contains($kw, ' list') || str_contains($kw, 'alternative') => 'List: Round-up',
            default => 'Guide: Explainer',
        };
    }

    /** Parse the model's JSON, tolerating accidental markdown fences. */
    private function parseJson(string $text): array
    {
        $clean = trim(preg_replace('/^```(?:json)?|```$/m', '', trim($text)) ?? '');
        $data = json_decode($clean, true);
        if (! is_array($data) || empty($data['html'])) {
            throw new RuntimeException('Anthropic returned an unparseable draft: '.mb_substr($text, 0, 200));
        }

        return $data;
    }
}
