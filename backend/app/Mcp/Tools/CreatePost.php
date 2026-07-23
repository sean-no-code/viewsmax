<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PostController;
use App\Models\Post;
use App\Services\Social\PostPublishDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class CreatePost extends ViewsMaxTool
{
    /** Common names AI clients use for platforms we support. */
    public const PLATFORM_ALIASES = ['twitter' => 'x'];

    /**
     * Caption character limits per platform, so the AI can trim before
     * calling create_post/update_post instead of finding out from the UI
     * after the draft is already saved. Sourced from the frontend's PLATFORM
     * catalog (src/components/post/composer.tsx) — keep these two in sync by
     * hand; the platforms rarely change their limits.
     */
    public const PLATFORM_CHAR_LIMITS = [
        'tiktok' => 2200,
        'youtube' => 5000,
        'x' => 280,
        'linkedin' => 3000,
        'threads' => 500,
        'instagram' => 2200,
        'facebook' => 5000,
        'bluesky' => 300,
    ];

    public function name(): string
    {
        return 'create_post';
    }

    public function description(): string
    {
        $platforms = implode(', ', PostPublishDispatcher::platforms());

        return "Compose a social post for one or more platforms ({$platforms}). "
            . 'Target either platforms[] or brand_id (from list_brands, posts to '
            . 'every connected account in the brand) — not both. '
            . 'status "draft" (default) saves without publishing, "posted" publishes '
            . 'immediately, "scheduled" publishes at scheduled_at. TikTok takes a '
            . 'video (with a url) or a photo slideshow (one or more image urls); '
            . 'YouTube requires a video with a path — both come from upload_media. '
            . 'Instagram needs an image or video url. '
            . 'TikTok photo slideshows accept options.tiktok.auto_add_music (boolean, '
            . 'default false) to let TikTok auto-add its recommended background music; '
            . 'there is no music option for video posts or for Instagram. '
            . 'Publishing/scheduling to TikTok requires options.tiktok.privacy_level '
            . '(one of PUBLIC_TO_EVERYONE, MUTUAL_FOLLOW_FRIENDS, FOLLOWER_OF_CREATOR, '
            . 'SELF_ONLY) — there is no default; branded_content cannot be SELF_ONLY. '
            . 'Caption character limits: ' . self::formatCharLimits() . '. '
            . 'Publishing is asynchronous: check per-platform results with get_post.';
    }

    /** e.g. "tiktok: 2200, youtube: 5000, x: 280, ..." */
    public static function formatCharLimits(): string
    {
        return collect(self::PLATFORM_CHAR_LIMITS)
            ->map(fn ($limit, $platform) => "{$platform}: {$limit}")
            ->implode(', ');
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->raw('platforms', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Target platforms: ' . implode(', ', PostPublishDispatcher::platforms())
                    . '. Omit when brand_id is given.',
            ])
            ->integer('brand_id')->description('Post to every connected account in this brand (see list_brands) instead of platforms.')->optional()
            ->string('caption')->description('Post text / caption.')->optional()
            ->string('status')->description('draft (default), scheduled, or posted.')->optional()
            ->string('scheduled_at')->description('ISO-8601 datetime; required when status is scheduled.')->optional()
            ->raw('media', [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['image', 'video']],
                        'url' => ['type' => 'string'],
                        'path' => ['type' => 'string'],
                    ],
                ],
                'description' => 'Media entries from upload_media (or any public URL).',
            ])
            ->raw('overrides', [
                'type' => 'object',
                'description' => 'Optional per-platform caption overrides, keyed by platform.',
            ])
            ->raw('options', [
                'type' => 'object',
                'description' => 'Optional per-platform publish options, keyed by platform. '
                    . 'Known keys — tiktok: { privacy_level, disable_comment, disable_duet, '
                    . 'disable_stitch, auto_add_music (boolean, default false — auto-adds '
                    . "TikTok's recommended music to photo slideshows; ignored for video) }; "
                    . 'youtube: { privacy_status: public|unlisted|private }; '
                    . 'instagram: { cover_url }; linkedin: { first_comment }.',
            ])
            ->raw('comments', [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => ['type' => 'string'],
                        'delay_seconds' => ['type' => 'integer'],
                    ],
                    'required' => ['body'],
                ],
                'description' => 'Optional follow-up comments posted after each target publishes '
                    . '(X reply threads, LinkedIn/Instagram comments, Threads replies; platforms '
                    . 'without a comment API skip them). delay_seconds (0-7200) waits before '
                    . 'posting, measured from the previous message in the chain. On X each body '
                    . 'must fit 280 characters.',
            ])
            ->boolean('shorten_links')->description('Replace URLs in the caption/overrides/comments with tracked short links (/l/{slug}) that count clicks.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        if ($limited = $this->hourlyLimit('create_post')) {
            return $limited;
        }

        // AI pre-guards only; PostController::store owns the payload rules.
        Validator::validate($arguments, [
            'platforms' => 'required_without:brand_id|array|min:1',
            'platforms.*' => 'string|max:40',
            'brand_id' => 'nullable|integer',
            'scheduled_at' => 'nullable|date|required_if:status,' . Post::STATUS_SCHEDULED,
        ]);

        $status = is_string($arguments['status'] ?? null) && $arguments['status'] !== ''
            ? $arguments['status']
            : Post::STATUS_DRAFT;
        $media = is_array($arguments['media'] ?? null) ? $arguments['media'] : [];

        $params = array_intersect_key($arguments, array_flip([
            'caption', 'status', 'scheduled_at', 'media', 'overrides', 'options', 'comments', 'shorten_links',
        ]));

        if (! empty($arguments['brand_id'])) {
            if (! empty($arguments['platforms'])) {
                return ToolResult::error('Provide platforms or brand_id, not both.');
            }

            [$targets, $platforms, $error] = $this->expandBrand((int) $arguments['brand_id']);
            if ($error !== null) {
                return ToolResult::error($error);
            }
            if ($error = self::videoRuleError($status, $platforms, $media)) {
                return ToolResult::error($error);
            }

            $params['targets'] = $targets;
            $params['brand_id'] = (int) $arguments['brand_id'];
        } else {
            $platforms = self::normalizePlatforms($arguments['platforms']);
            if ($error = self::unsupportedPlatformError($platforms)) {
                return ToolResult::error($error);
            }
            if ($error = self::videoRuleError($status, $platforms, $media)) {
                return ToolResult::error($error);
            }

            $params['platforms'] = $platforms;
        }

        return $this->callController(
            fn (Request $request) => app(PostController::class)->store($request),
            $params,
            fn (array $data) => $this->serializePost(
                $this->user()->posts()->with('targets')->findOrFail($data['id'])
            )
        );
    }

    /**
     * Expand a brand into account-pinned targets: healthy social accounts are
     * pinned, legacy connections (YouTube/TikTok) post unpinned, accounts that
     * can't post right now (needs_reauth etc.) are skipped.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>, 2: ?string} [targets, platforms, error]
     */
    private function expandBrand(int $brandId): array
    {
        $brand = $this->user()->brands()->with(['socialAccounts', 'connections'])->find($brandId);
        if (! $brand) {
            return [[], [], 'Brand not found. Use list_brands to see available brands.'];
        }

        $targets = $brand->socialAccounts
            ->where('status', \App\Models\SocialAccount::STATUS_CONNECTED)
            ->map(fn ($a) => ['platform' => $a->platform, 'social_account_id' => $a->id])
            ->values();

        $legacy = $brand->connections
            ->pluck('provider')
            ->unique()
            ->map(fn ($p) => ['platform' => $p]);

        $targets = $targets->concat($legacy)->values()->all();
        if ($targets === []) {
            return [[], [], "Brand \"{$brand->name}\" has no connected accounts that can post right now."];
        }

        return [$targets, array_values(array_unique(array_column($targets, 'platform'))), null];
    }

    /** Lowercase and map aliases (e.g. twitter -> x), dropping duplicates. */
    public static function normalizePlatforms(array $platforms): array
    {
        return collect($platforms)
            ->map(fn ($p) => strtolower(trim((string) $p)))
            ->map(fn ($p) => self::PLATFORM_ALIASES[$p] ?? $p)
            ->unique()
            ->values()
            ->all();
    }

    public static function unsupportedPlatformError(array $platforms): ?string
    {
        $supported = PostPublishDispatcher::platforms();
        $unsupported = array_values(array_diff($platforms, $supported));

        return $unsupported
            ? 'Unsupported platform(s): ' . implode(', ', $unsupported)
                . '. Supported platforms: ' . implode(', ', $supported) . '.'
            : null;
    }

    /**
     * Pre-guard mirroring PostController's publish-time media rules so the AI
     * gets an actionable error (pointing at upload_media) before delegation.
     * On store the controller enforces the same rules; on update it only does
     * when platforms are resent, so UpdatePost relies on this for the
     * keep-existing-targets case.
     */
    public static function videoRuleError(string $status, array $platforms, array $media): ?string
    {
        $publishing = in_array($status, [Post::STATUS_SCHEDULED, Post::STATUS_POSTED], true);
        if (! $publishing) {
            return null;
        }

        $hasVideoUrl = collect($media)->contains(fn ($m) => ($m['type'] ?? null) === 'video' && ! empty($m['url']));
        $hasVideoPath = collect($media)->contains(fn ($m) => ($m['type'] ?? null) === 'video' && ! empty($m['path']));
        $hasImageUrl = collect($media)->contains(fn ($m) => ($m['type'] ?? null) === 'image' && ! empty($m['url']));

        if (in_array('tiktok', $platforms, true) && ! $hasVideoUrl && ! $hasImageUrl) {
            return 'TikTok requires a video (with a url) or at least one image (a photo slideshow) before publishing or scheduling. Use upload_media first.';
        }
        if (in_array('youtube', $platforms, true) && ! $hasVideoPath) {
            return 'YouTube requires a video media entry with a path before publishing or scheduling. Use upload_media first.';
        }

        return null;
    }
}
