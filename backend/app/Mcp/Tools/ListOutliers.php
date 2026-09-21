<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\OutlierController;
use App\Models\OutlierVideo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('List outlier videos')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class ListOutliers extends ViewsMaxTool
{
    private const MAX_PER_PAGE = 50;

    protected function requiresWrite(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'list_outliers';
    }

    public function description(): string
    {
        return 'Browse outlier videos — content that massively over-performed its channel\'s '
            . 'average (outlier_score = views ÷ channel average views) across YouTube, TikTok '
            . 'and Instagram. Without `query` this is the curated/featured feed; with `query` '
            . 'it returns title matches already in the database. If the response `status` is '
            . '"queued" or "in_progress" no scrape has finished for that query yet — call '
            . 'search_outliers to start one, then re-run this tool. Filter by platform, '
            . 'score, views, subscribers, publish date, duration (long/shorts), channel ids '
            . 'or ISO country codes. Paginated (per_page ≤ 50).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')->description('Keyword/topic; matches video titles.')->optional()
            ->string('platform')->description('youtube, tiktok, or instagram. Omit for all.')->optional()
            ->string('sort_by')->description('score (default), views, date, or recent.')->optional()
            ->number('min_score')->description('Minimum outlier score (multiple of the channel average).')->optional()
            ->integer('min_views')->optional()
            ->integer('max_views')->optional()
            ->integer('min_subs')->description('Minimum channel subscribers/followers.')->optional()
            ->integer('max_subs')->optional()
            ->string('published_after')->description('ISO-8601 date.')->optional()
            ->string('published_before')->description('ISO-8601 date.')->optional()
            ->string('duration_type')->description('long or shorts.')->optional()
            ->boolean('featured')->description('Only the curated featured list.')->optional()
            ->raw('channels', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Channel ids (from a previous result\'s channel.id).'])->optional()
            ->raw('countries', ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Two-letter ISO country codes of the channel.'])->optional()
            ->integer('page')->optional()
            ->integer('per_page')->description('1-50, default 20.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, [
            'query' => 'nullable|string|max:200',
            'platform' => 'nullable|string|in:youtube,tiktok,instagram',
            'sort_by' => 'nullable|string|in:score,date,views,recent',
            'min_score' => 'nullable|numeric|min:0',
            'min_views' => 'nullable|integer|min:0',
            'max_views' => 'nullable|integer|min:0',
            'min_subs' => 'nullable|integer|min:0',
            'max_subs' => 'nullable|integer|min:0',
            'published_after' => 'nullable|date',
            'published_before' => 'nullable|date',
            'duration_type' => 'nullable|string|in:' . OutlierVideo::DURATION_TYPE_LONG . ',' . OutlierVideo::DURATION_TYPE_SHORTS,
            'featured' => 'nullable|boolean',
            'channels' => 'nullable|array|max:50',
            'channels.*' => 'string',
            'countries' => 'nullable|array|max:50',
            'countries.*' => 'string|size:2|alpha',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:' . self::MAX_PER_PAGE,
        ]);

        return $this->callController(
            fn (Request $request) => app(OutlierController::class)->index($request),
            array_filter($validated, fn ($v) => $v !== null),
            fn (array $data) => [
                'status' => $data['status'] ?? 'done',
                'outliers' => array_map(fn ($v) => $this->serializeOutlier((array) $v), $data['data'] ?? []),
                'page' => $data['current_page'] ?? 1,
                'per_page' => $data['per_page'] ?? null,
                'total' => $data['total'] ?? null,
                'last_page' => $data['last_page'] ?? null,
            ]
        );
    }
}
