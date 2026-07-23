<?php

namespace App\Http\Controllers;

use App\Services\OAuthConnectionService;
use App\Services\TikTokPublishService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * @group Connections
 *
 * Legacy connection management (YouTube/TikTok/Instagram OAuth token
 * exchange). Prefer the /api/social endpoints for new integrations.
 */
class ConnectionController extends Controller
{
    /**
     * List the authenticated user's connections.
     */
    public function index(Request $request)
    {
        $connections = $request->user()
            ->connections()
            ->orderBy('created_at')
            ->get();

        // TikTok avatar URLs are short-lived signed links: refresh stale
        // profiles (≥24h) so the UI keeps showing a live image + name. Best
        // effort — an API hiccup must never break the connections list.
        foreach ($connections as $connection) {
            if ($connection->provider === 'tiktok' && $connection->updated_at?->lt(now()->subDay())) {
                try {
                    app(\App\Services\OAuthConnectionService::class)->refreshProfile($connection);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('TikTok profile refresh failed', [
                        'connection_id' => $connection->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json([
            'data' => $connections->map(fn ($connection) => $connection->toApiArray()),
        ]);
    }

    /**
     * Exchange an OAuth code for a provider and upsert the connection.
     * (YouTube is handled by YouTubeOAuthController; this covers tiktok|instagram.)
     */
    public function exchange(Request $request, string $provider, OAuthConnectionService $service)
    {
        if (! in_array($provider, OAuthConnectionService::PROVIDERS, true)) {
            return response()->json(['message' => 'Unsupported provider.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'redirect_uri' => 'required|string|url',
            'code_verifier' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $connection = $service->exchange(
                $request->user(),
                $provider,
                $request->input('code'),
                $request->input('redirect_uri'),
                $request->input('code_verifier'),
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'connection' => $connection->toApiArray(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Connection exchange failed', [
                'provider' => $provider,
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to connect '.$provider.'. '.$e->getMessage(),
            ], 422);
        }
    }

    /**
     * Return the TikTok creator's allowed posting options (privacy levels,
     * comment/duet/stitch availability, max duration). The composer must render
     * the privacy/interaction UI from this before a post can be published.
     */
    public function tiktokCreatorInfo(Request $request, TikTokPublishService $tiktok)
    {
        // Multi-account: creator info is per TikTok account. The composer passes
        // the selected account_id; default to the newest connected one.
        $query = $request->user()->socialAccounts()->where('platform', 'tiktok');
        if ($request->filled('account_id')) {
            $query->where('id', $request->integer('account_id'));
        }
        $account = $query->latest()->first();

        if (! $account) {
            return response()->json(['message' => 'No TikTok account connected.'], 404);
        }

        try {
            return response()->json(['data' => $tiktok->creatorInfo($account)]);
        } catch (\Exception $e) {
            Log::error('TikTok creator info failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Delete a connection belonging to the caller.
     */
    public function destroy(Request $request, int $id)
    {
        $connection = $request->user()->connections()->find($id);

        if (! $connection) {
            return response()->json(['message' => 'Connection not found.'], 404);
        }

        $connection->delete();

        return response()->json(['success' => true]);
    }
}
