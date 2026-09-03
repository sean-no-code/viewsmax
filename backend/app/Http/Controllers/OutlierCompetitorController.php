<?php

namespace App\Http\Controllers;

use App\Models\OutlierChannel;
use App\Models\OutlierCompetitorChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Per-user competitor channels, picked from the outlier DB (e.g. from a video
 * breakdown page). Add is idempotent; delete is by channel id so the UI can
 * toggle without tracking row ids.
 */
class OutlierCompetitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rows = OutlierCompetitorChannel::with('channel')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($c) => [
                'channel_id' => $c->channel_id,
                'platform' => $c->channel?->platform,
                'channel_name' => $c->channel?->channel_name,
                'added_at' => optional($c->created_at)->toISOString(),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate(['channel_id' => 'required|integer']);

        // exists: can't span connections — verify on outlier_db explicitly.
        if (! OutlierChannel::whereKey($validated['channel_id'])->exists()) {
            return response()->json(['message' => 'Unknown channel.'], 422);
        }

        $row = OutlierCompetitorChannel::firstOrCreate([
            'user_id' => $request->user()->id,
            'channel_id' => (int) $validated['channel_id'],
        ]);

        return response()->json(['data' => ['channel_id' => $row->channel_id]]);
    }

    public function destroy(Request $request, int $channelId): JsonResponse
    {
        OutlierCompetitorChannel::where('user_id', $request->user()->id)
            ->where('channel_id', $channelId)
            ->delete();

        return response()->json(['data' => ['channel_id' => $channelId, 'removed' => true]]);
    }
}
