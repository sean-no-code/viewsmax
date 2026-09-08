<?php

namespace App\Http\Controllers;

use App\Models\OutlierTag;
use App\Models\SavedOutlier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Outliers
 *
 * The user's saved-outliers library: bookmarked videos with tags.
 */
class SavedOutlierController extends Controller
{
    /**
     * List saved outliers
     *
     * The user's library, newest first.
     *
     * @queryParam q string Matches saved title or channel name. Example: hook
     * @queryParam tags string[] Only items carrying any of these tag names.
     * @queryParam platforms string[] youtube, tiktok, instagram.
     * @queryParam creator string Channel/creator name filter.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SavedOutlier::where('user_id', $request->user()->id)
            ->with('tags')
            ->orderByDesc('created_at');

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($sub) use ($q) {
                $sub->where('snapshot->title', 'like', "%{$q}%")
                    ->orWhere('snapshot->channel_name', 'like', "%{$q}%");
            });
        }

        if ($request->filled('tags')) {
            $tagNames = (array) $request->input('tags');
            $query->whereHas('tags', fn ($t) => $t->whereIn('name', $tagNames));
        }

        if ($request->filled('platforms')) {
            $query->whereIn('platform', (array) $request->input('platforms'));
        }

        if ($request->filled('creator')) {
            $query->where('snapshot->channel_name', 'like', '%'.$request->input('creator').'%');
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /**
     * Save an outlier
     *
     * Bookmark a video into the library with a snapshot of its stats. Saving the
     * same video again replaces its tags.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => 'required|string|in:youtube,tiktok,instagram',
            'video_id' => 'required|string',
            'snapshot' => 'required|array',
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);

        $saved = SavedOutlier::updateOrCreate(
            ['user_id' => $request->user()->id, 'platform' => $validated['platform'], 'video_id' => $validated['video_id']],
            ['snapshot' => $validated['snapshot']],
        );

        $this->syncTags($saved, $request);

        return response()->json(['success' => true, 'data' => $saved->load('tags')], 201);
    }

    /**
     * Update a saved outlier's tags
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $saved = SavedOutlier::where('user_id', $request->user()->id)->findOrFail($id);

        $request->validate([
            'tags' => 'sometimes|array',
            'tags.*' => 'string|max:50',
        ]);

        $this->syncTags($saved, $request);

        return response()->json(['success' => true, 'data' => $saved->load('tags')]);
    }

    /**
     * Remove a saved outlier
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $saved = SavedOutlier::where('user_id', $request->user()->id)->findOrFail($id);
        $saved->delete();

        return response()->json(['success' => true, 'message' => 'Removed from library']);
    }

    /**
     * List library tags
     *
     * Distinct tag names the user has applied to saved outliers.
     */
    public function tags(Request $request): JsonResponse
    {
        $tags = OutlierTag::where('user_id', $request->user()->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => $tags]);
    }

    /** Sync the saved outlier's tags (per-user, created on demand by name). */
    private function syncTags(SavedOutlier $saved, Request $request): void
    {
        if (! $request->has('tags')) {
            return;
        }

        $tagIds = [];
        foreach ((array) $request->input('tags') as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $tag = OutlierTag::firstOrCreate(['user_id' => $request->user()->id, 'name' => $name]);
            $tagIds[] = $tag->id;
        }

        $saved->tags()->sync($tagIds);
    }
}
