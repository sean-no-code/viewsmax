<?php

namespace App\Mcp\Tools;

use App\Jobs\GenerateOutlierBreakdownJob;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolResult;
use Symfony\Component\HttpFoundation\Response;

abstract class ViewsMaxTool extends Tool
{
    /**
     * Whether this tool mutates account state. Write tools need the
     * `mcp:write` scope; read tools need only `mcp:read`. Defaults to true
     * (fail-closed) so a new tool is write-gated until it opts out.
     */
    protected function requiresWrite(): bool
    {
        return true;
    }

    /**
     * Public view of requiresWrite() for discovery surfaces (/api/ai) that
     * describe tools without an authenticated MCP request in flight.
     */
    public function isWrite(): bool
    {
        return $this->requiresWrite();
    }

    /**
     * Scope the tool to the request's granted access. An unregistered tool is
     * hidden from tools/list AND unresolvable on tools/call (the server
     * filters both through shouldRegister), so this is the single enforcement
     * point for read-only vs. full-access credentials.
     */
    public function shouldRegister(): bool
    {
        $granted = \App\Http\Middleware\McpAuth::grantedScopes(request());

        return in_array($this->requiresWrite() ? 'mcp:write' : 'mcp:read', $granted, true);
    }

    /**
     * The authenticated user (resolved from the MCP API key by McpAuth).
     */
    protected function user(): User
    {
        return request()->user();
    }

    /**
     * Per-user hourly throttle for expensive tools. Returns an error result
     * when the budget (config/mcp.php rate_limits.{key}_per_hour) is spent.
     */
    protected function hourlyLimit(string $key): ?ToolResult
    {
        $max = (int) config("mcp.rate_limits.{$key}_per_hour");
        $bucket = sprintf('mcp-tool:%s:%d', $key, $this->user()->id);

        if (RateLimiter::tooManyAttempts($bucket, $max)) {
            return ToolResult::error(
                "Rate limit exceeded: at most {$max} {$this->name()} calls per hour. "
                . 'Try again in ' . RateLimiter::availableIn($bucket) . ' seconds.'
            );
        }

        RateLimiter::hit($bucket, 3600);

        return null;
    }

    /**
     * Invoke an existing API controller action with a synthetic request so
     * tools reuse the exact REST behavior (plan limits, ownership checks,
     * validation) instead of duplicating it. ValidationException is left to
     * bubble up — the MCP layer converts it into a tool error. An optional
     * $transform reshapes the successful response data for tool output.
     *
     * Temporary seam: the pre-MCP code is frozen, so tools call controllers
     * in-process. Once that code is editable, extract each endpoint's gate +
     * validation rules + persistence into an action class shared by the
     * controller and the tool, then delete this.
     */
    protected function callController(callable $action, array $params = [], ?callable $transform = null): ToolResult
    {
        $request = new Request($params);
        $request->setUserResolver(fn () => $this->user());

        try {
            $response = $action($request);
        } catch (ModelNotFoundException) {
            return ToolResult::error('Not found.');
        } catch (HttpResponseException $e) {
            // Controllers that abort(response()->json(..., 4xx)) land here.
            $response = $e->getResponse();
        }

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            if ($response->getStatusCode() >= 400) {
                return ToolResult::error(
                    is_array($data) ? ($data['message'] ?? json_encode($data)) : (string) $data
                );
            }

            $data = is_array($data) ? $data : ['data' => $data];

            return ToolResult::json($transform ? $transform($data) : $data);
        }

        if ($response instanceof Response) {
            return $response->getStatusCode() >= 400
                ? ToolResult::error('Request failed (HTTP ' . $response->getStatusCode() . ').')
                : ToolResult::json(['success' => true]);
        }

        if ($response instanceof Arrayable) {
            $data = $response->toArray();

            return ToolResult::json($transform ? $transform($data) : $data);
        }

        $data = is_array($response) ? $response : ['data' => $response];

        return ToolResult::json($transform ? $transform($data) : $data);
    }

    /**
     * Shape a post (with targets) for tool output.
     */
    protected function serializePost(Post $post): array
    {
        return [
            'id' => $post->id,
            'caption' => $post->caption,
            'status' => $post->status,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'media' => $post->media ?? [],
            'created_at' => $post->created_at?->toIso8601String(),
            'targets' => $post->targets->map(fn ($t) => [
                'platform' => $t->platform,
                'status' => $t->status,
                'error' => $t->error,
                'platform_post_id' => $t->platform_post_id,
                'published_at' => $t->published_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Shape an outlier video (as serialized by the REST controllers, with its
     * channel) for tool output. Works on the JSON array form so tools can
     * reuse controller responses unchanged.
     */
    protected function serializeOutlier(array $video): array
    {
        $platform = (string) ($video['platform'] ?? 'youtube');
        $videoId = (string) ($video['youtube_video_id'] ?? '');
        $channel = is_array($video['channel'] ?? null) ? $video['channel'] : null;

        return [
            'platform' => $platform,
            'video_id' => $videoId,
            'url' => GenerateOutlierBreakdownJob::nativeUrl($platform, $videoId, $channel['channel_name'] ?? null),
            'title' => $video['title'] ?? null,
            'thumbnail_url' => $video['thumbnail_url'] ?? null,
            'views' => $video['views'] ?? null,
            'like_count' => $video['like_count'] ?? null,
            'comment_count' => $video['comment_count'] ?? null,
            'outlier_score' => $video['outlier_score'] ?? null,
            'engagement_rate' => $video['engagement_rate'] ?? null,
            'duration' => $video['formatted_duration'] ?? $video['duration'] ?? null,
            'duration_seconds' => $video['duration_in_seconds'] ?? null,
            'is_short' => $video['is_short'] ?? null,
            'published_at' => $video['published_at'] ?? null,
            'featured' => (bool) ($video['featured'] ?? false),
            'channel' => $channel ? [
                'id' => $channel['youtube_channel_id'] ?? null,
                'name' => $channel['channel_name'] ?? null,
                'platform' => $channel['platform'] ?? $platform,
                'subscriber_count' => $channel['subscriber_count'] ?? null,
                'average_views' => $channel['average_views'] ?? null,
                'country' => $channel['country'] ?? null,
                'avatar' => $channel['profile_image_url'] ?? null,
            ] : null,
        ];
    }
}
