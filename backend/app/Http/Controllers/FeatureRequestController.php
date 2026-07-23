<?php

namespace App\Http\Controllers;

use App\Models\FeatureRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * @group Feature Requests
 */
class FeatureRequestController extends Controller
{
    /**
     * List feature requests, sorted by top votes (default) or newest.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        $sort = $request->query('sort', 'top');

        $query = FeatureRequest::query()
            ->withCount('votes as upvotes_count')
            ->with(['votes' => fn ($q) => $q->where('user_id', $userId)]);

        if ($sort === 'new') {
            $query->orderByDesc('created_at');
        } else {
            $query->orderByDesc('upvotes_count')->orderByDesc('created_at');
        }

        $data = $query->get()->map(fn (FeatureRequest $fr) => $fr->toApiArray($userId));

        return response()->json(['data' => $data]);
    }

    /**
     * Create a feature request and auto-upvote it for the creator.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'required|string',
            'category' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        $featureRequest = FeatureRequest::create([
            'user_id' => $user->id,
            'title' => $request->input('title'),
            'description' => $request->input('description'),
            'category' => $request->input('category'),
        ]);

        // Auto-upvote by the creator.
        $featureRequest->votes()->create(['user_id' => $user->id]);

        $featureRequest->loadCount('votes as upvotes_count')
            ->load(['votes' => fn ($q) => $q->where('user_id', $user->id)]);

        return response()->json([
            'data' => $featureRequest->toApiArray($user->id),
        ], 201);
    }

    /**
     * Toggle the caller's vote on a feature request.
     */
    public function upvote(Request $request, int $id)
    {
        $featureRequest = FeatureRequest::find($id);

        if (! $featureRequest) {
            return response()->json(['message' => 'Feature request not found.'], 404);
        }

        $user = $request->user();
        $existing = $featureRequest->votes()->where('user_id', $user->id)->first();

        if ($existing) {
            $existing->delete();
            $hasUpvoted = false;
        } else {
            $featureRequest->votes()->create(['user_id' => $user->id]);
            $hasUpvoted = true;
        }

        return response()->json([
            'data' => [
                'upvotes_count' => $featureRequest->votes()->count(),
                'has_upvoted' => $hasUpvoted,
            ],
        ]);
    }
}
