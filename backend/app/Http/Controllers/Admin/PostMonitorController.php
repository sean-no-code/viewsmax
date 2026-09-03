<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Social\PostPublishDispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;

/**
 * Admin-only monitoring of publishing across all clients. The route group is
 * already gated by `role:admin`; this controller also declares the guard itself
 * (defense-in-depth) so it stays protected even if a route is ever registered
 * outside that group.
 */
class PostMonitorController extends Controller implements HasMiddleware
{
    // A publishing target updated more recently than this is treated as possibly
    // in-flight and never re-dispatched (avoids double-posting).
    private const REQUEUE_INFLIGHT_MINUTES = 15;

    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }


    /**
     * All clients' posts (drafts excluded) with owner + per-platform targets.
     * Optional filters: status (a target status), q (caption / client search).
     */
    public function index(Request $request)
    {
        // Exclude the (potentially large) meta audit log from the list payload;
        // it's fetched on demand via show(). delivery_mode (direct | inbox) is
        // pulled out of meta so the list can flag inbox-delivered TikTok posts.
        $targetColumns = ['id', 'post_id', 'platform', 'caption_override', 'status', 'platform_post_id', 'error', 'published_at', 'options'];

        $query = Post::query()
            ->with(['targets' => fn ($q) => $q->select($targetColumns)->addSelect('meta->mode as delivery_mode'), 'user:id,name,email'])
            ->where('status', '!=', 'draft')
            ->latest();

        // Post-level status (e.g. the "scheduled" list) — distinct from the
        // per-target status filter below.
        if ($request->filled('post_status')) {
            $query->where('status', $request->input('post_status'));
        }

        // Overdue scheduled posts: still scheduled but past their time (the
        // scheduler missed them). Powers the "overdue" quick-filter/highlight.
        if ($request->boolean('overdue')) {
            $query->where('status', Post::STATUS_SCHEDULED)
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '<=', now());
        }

        if ($request->filled('platform')) {
            $query->whereHas('targets', fn ($q) => $q->where('platform', $request->input('platform')));
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->whereHas('targets', fn ($q) => $q->where('status', $status));
        }

        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(function ($q) use ($term) {
                $q->where('caption', 'ilike', "%{$term}%")
                    ->orWhereHas('user', fn ($u) => $u
                        ->where('name', 'ilike', "%{$term}%")
                        ->orWhere('email', 'ilike', "%{$term}%"))
                    ->orWhereHas('targets', fn ($t) => $t
                        ->where('caption_override', 'ilike', "%{$term}%")
                        ->orWhere('platform_post_id', 'ilike', "%{$term}%"));
            });
        }

        return ['data' => $query->limit(200)->get()];
    }

    /**
     * Read-only scan for publishing left in a bad state by a worker/scheduler
     * outage. Changes nothing — powers the "Reconcile" report button.
     */
    public function reconcile()
    {
        $stuck = PostTarget::stuck()->get(['id', 'post_id', 'status', 'platform_post_id']);
        $requeueable = $stuck->whereNull('platform_post_id')->count();

        $overdueScheduled = Post::where('status', Post::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->count();

        return ['data' => [
            'stuck_targets' => $stuck->count(),
            'requeueable' => $requeueable,
            'already_on_platform' => $stuck->count() - $requeueable,
            'overdue_scheduled' => $overdueScheduled,
            'affected_post_ids' => $stuck->pluck('post_id')->unique()->values(),
        ]];
    }

    /**
     * Re-drive one post's unfinished platform targets. Safe against double-posts:
     * a target that already reached the platform (has a platform_post_id) is
     * marked published rather than re-dispatched. An overdue scheduled post is
     * promoted to posted first so its targets can go out.
     */
    public function requeue(Post $post)
    {
        $post->load('targets');

        if ($post->status === Post::STATUS_SCHEDULED) {
            $post->update(['status' => Post::STATUS_POSTED]);
        }

        // A 'publishing' target updated within this window may have a job still in
        // flight (platform_post_id is only written when the publish call returns),
        // so re-dispatching it would double-post. Leave those alone.
        $inFlightCutoff = now()->subMinutes(self::REQUEUE_INFLIGHT_MINUTES);

        $requeued = 0;
        $healed = 0;
        $skipped = 0;

        foreach ($post->targets as $target) {
            if ($target->status === PostTarget::STATUS_PUBLISHED) {
                $skipped++;

                continue;
            }

            if (! is_null($target->platform_post_id)) {
                // Already on the platform — heal, never re-dispatch (double-post).
                $target->update(['status' => PostTarget::STATUS_PUBLISHED, 'published_at' => $target->published_at ?? now()]);
                $healed++;

                continue;
            }

            // Skip a target whose publish job may still be running (recently
            // dispatched). 'pending' (never dispatched) and 'failed' (terminal)
            // carry no in-flight risk and are safe to re-drive immediately.
            if ($target->status === PostTarget::STATUS_PUBLISHING
                && $target->updated_at
                && $target->updated_at->greaterThan($inFlightCutoff)) {
                $skipped++;

                continue;
            }

            PostPublishDispatcher::dispatchTarget($target) ? $requeued++ : $skipped++;
        }

        Log::info('admin requeue', ['post_id' => $post->id, 'requeued' => $requeued, 'healed' => $healed, 'skipped' => $skipped]);

        return ['data' => [
            'post_id' => $post->id,
            'status' => $post->status,
            'requeued' => $requeued,
            'healed' => $healed,
            'skipped' => $skipped,
            'targets' => $post->fresh('targets')->targets,
        ]];
    }

    /**
     * Full detail for one post: owner + every platform target including the
     * `meta` audit log (raw TikTok request/response trail) for reviewing
     * failures.
     */
    public function show(Post $post)
    {
        $post->load(['targets', 'user:id,name,email']);

        return ['data' => $post];
    }

    /**
     * Aggregate counts for the admin dashboard header.
     */
    public function stats()
    {
        $byStatus = \App\Models\PostTarget::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        return [
            'data' => [
                'published' => (int) ($byStatus['published'] ?? 0),
                'failed' => (int) ($byStatus['failed'] ?? 0),
                'publishing' => (int) ($byStatus['publishing'] ?? 0),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'posts' => Post::where('status', '!=', 'draft')->count(),
            ],
        ];
    }
}
