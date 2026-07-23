<?php

namespace App\Http\Controllers;

use App\Http\Resources\SocialPostResource;
use App\Jobs\PublishSocialPostJob;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @group Social Posts
 *
 * Earlier posting surface (predates /api/posts). Prefer the Posts endpoints
 * for new integrations; these remain for existing clients.
 */
class SocialPostController extends Controller
{
    /**
     * The user's X posts published through the app (both posting stores),
     * newest first — used to pin a tracking link to a live tweet. Pure DB
     * read; the X API plan doesn't allow timeline reads.
     */
    public function xPosts(\App\Services\XPublishedPostsService $service)
    {
        return ['data' => $service->listFor(Auth::id())];
    }

    /**
     * List the user's posts (most recent first).
     */
    public function index(Request $request)
    {
        $posts = Auth::user()->socialPosts()
            ->with('targets')
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return SocialPostResource::collection($posts)
            ->additional(['success' => true]);
    }

    public function show(int $id)
    {
        $post = Auth::user()->socialPosts()->with('targets')->findOrFail($id);

        return (new SocialPostResource($post))->additional(['success' => true]);
    }

    /**
     * Create a post and publish (or schedule) it to the chosen accounts.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'content' => 'nullable|string|max:10000',
            'link' => 'nullable|string|url|max:1024',
            'media' => 'nullable|array',
            'media.*.url' => 'required_with:media|string|url',
            'media.*.type' => 'nullable|in:image,video',
            'media.*.mime' => 'nullable|string',
            'media.*.alt' => 'nullable|string',
            'account_ids' => 'required|array|min:1',
            'account_ids.*' => 'integer',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        $user = Auth::user();

        if (empty($validated['content']) && empty($validated['media'])) {
            return response()->json([
                'success' => false,
                'message' => 'A post needs text content or media.',
            ], 422);
        }

        // Only allow posting to the user's own connected accounts.
        $accounts = $user->socialAccounts()
            ->whereIn('id', $validated['account_ids'])
            ->get();

        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'None of the selected accounts belong to you.',
            ], 422);
        }

        $isScheduled = ! empty($validated['scheduled_at']);

        $post = DB::transaction(function () use ($user, $validated, $accounts, $isScheduled) {
            $post = $user->socialPosts()->create([
                'content' => $validated['content'] ?? null,
                'media' => $validated['media'] ?? null,
                'link' => $validated['link'] ?? null,
                'status' => $isScheduled ? SocialPost::STATUS_SCHEDULED : SocialPost::STATUS_QUEUED,
                'scheduled_at' => $validated['scheduled_at'] ?? null,
            ]);

            foreach ($accounts as $account) {
                $post->targets()->create([
                    'social_account_id' => $account->id,
                    'platform' => $account->platform,
                    'status' => SocialPostTarget::STATUS_PENDING,
                ]);
            }

            return $post;
        });

        // Dispatch immediately, or defer to the scheduled time.
        foreach ($post->targets as $target) {
            $job = new PublishSocialPostJob($target->id);

            if ($isScheduled) {
                $job->delay($post->scheduled_at);
            }

            dispatch($job);
        }

        $post->load('targets');

        return (new SocialPostResource($post))
            ->additional([
                'success' => true,
                'message' => $isScheduled
                    ? 'Post scheduled for '.$post->scheduled_at->toDayDateTimeString().'.'
                    : 'Post queued for publishing.',
            ])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Retry the failed targets of a post.
     */
    public function retry(int $id)
    {
        $post = Auth::user()->socialPosts()->with('targets')->findOrFail($id);

        $failed = $post->targets->where('status', SocialPostTarget::STATUS_FAILED);

        foreach ($failed as $target) {
            $target->update(['status' => SocialPostTarget::STATUS_PENDING, 'error' => null]);
            dispatch(new PublishSocialPostJob($target->id));
        }

        return response()->json([
            'success' => true,
            'message' => $failed->count().' target(s) re-queued.',
        ]);
    }
}
