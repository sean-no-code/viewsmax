<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\PostController;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Update a post')]
#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(true)]
class UpdatePost extends ViewsMaxTool
{
    public function name(): string
    {
        return 'update_post';
    }

    public function description(): string
    {
        return 'Edit a draft or scheduled post: caption, media, platforms, schedule, '
            . 'or status. Setting status to "posted" publishes immediately. Posts '
            . 'that have already been published cannot be edited. '
            . CreatePost::privacyRules() . ' '
            . 'Caption character limits: ' . CreatePost::formatCharLimits() . '.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('id')->description('The post id.')->required()
            ->string('caption')->description('New caption.')->optional()
            ->string('status')->description('draft, scheduled, or posted.')->optional()
            ->string('scheduled_at')->description('ISO-8601 datetime for scheduled posts.')->optional()
            ->raw('platforms', [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Replaces the full platform list when provided.',
            ])
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
                'description' => 'Replaces the media list when provided.',
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
                'description' => 'Replace the post\'s follow-up comments (see create_post). '
                    . 'Pass an empty array to remove them all.',
            ])
            ->boolean('shorten_links')->description('Replace URLs in the caption/overrides/comments with tracked short links that count clicks.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['id' => 'required|integer']);

        $post = $this->user()->posts()->with('targets')->find($validated['id']);

        if (! $post) {
            return ToolResult::error("Post {$validated['id']} not found.");
        }

        if ($post->status === Post::STATUS_POSTED) {
            return ToolResult::error('This post has already been published and cannot be edited.');
        }

        $platforms = null;
        if (is_array($arguments['platforms'] ?? null)) {
            $platforms = CreatePost::normalizePlatforms($arguments['platforms']);
            if ($error = CreatePost::unsupportedPlatformError($platforms)) {
                return ToolResult::error($error);
            }
        }

        $status = is_string($arguments['status'] ?? null) && $arguments['status'] !== ''
            ? $arguments['status']
            : $post->status;
        $media = is_array($arguments['media'] ?? null) ? $arguments['media'] : ($post->media ?? []);

        $scheduledAt = $arguments['scheduled_at'] ?? $post->scheduled_at?->toIso8601String();
        if ($error = CreatePost::pastScheduleError($status, $scheduledAt)) {
            return ToolResult::error($error);
        }
        $checkPlatforms = $platforms ?? $post->targets->pluck('platform')->all();
        if ($error = CreatePost::videoRuleError($status, $checkPlatforms, $media)) {
            return ToolResult::error($error);
        }

        // PostController::update owns the payload rules and partial-update
        // semantics; only pass platforms once normalized (a present-but-null
        // key would wipe the targets).
        $params = CreatePost::scheduledAtInUtc(array_intersect_key($arguments, array_flip([
            'caption', 'media', 'status', 'scheduled_at', 'overrides', 'options', 'comments', 'shorten_links',
        ])));
        if ($platforms !== null) {
            $params['platforms'] = $platforms;
        }

        return $this->callController(
            fn (Request $request) => app(PostController::class)->update($request, (string) $post->id),
            $params,
            fn () => $this->serializePost($post->refresh()->load('targets'))
        );
    }
}
