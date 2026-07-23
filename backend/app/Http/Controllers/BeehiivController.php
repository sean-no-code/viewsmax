<?php

namespace App\Http\Controllers;

use App\Models\BeehiivConnection;
use App\Services\BeehiivService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-user Beehiiv API-key connection. The key is validated against the Beehiiv
 * API on save, stored encrypted (BeehiivConnection cast), and never returned to
 * the client — responses only carry a masked hint + the connected publication.
 */
class BeehiivController extends Controller
{
    public function show()
    {
        $conn = Auth::user()->beehiivConnection;

        return response()->json([
            'success' => true,
            'data' => $conn ? $this->payload($conn) : ['connected' => false],
        ]);
    }

    public function store(Request $request, BeehiivService $beehiiv)
    {
        $data = $request->validate([
            'api_key' => 'required|string|min:8|max:400',
            'publication_id' => 'nullable|string',
        ]);
        $key = trim($data['api_key']);

        try {
            $publications = $beehiiv->fetchPublications($key);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => 'That Beehiiv API key was rejected — double-check it and try again.'], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Couldn’t reach Beehiiv right now. Please try again shortly.'], 502);
        }

        if (empty($publications)) {
            return response()->json(['success' => false, 'message' => 'This API key has no publications.'], 422);
        }

        // Honour a chosen publication, else default to the first.
        $pub = collect($publications)->firstWhere('id', $data['publication_id'] ?? null) ?? $publications[0];

        $conn = BeehiivConnection::updateOrCreate(
            ['user_id' => Auth::id()],
            [
                'api_key' => $key,
                'publication_id' => $pub['id'],
                'publication_name' => $pub['name'] ?? null,
                'status' => BeehiivConnection::STATUS_CONNECTED,
                'last_error' => null,
                'last_validated_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Beehiiv connected.',
            'data' => $this->payload($conn, $publications),
        ]);
    }

    public function destroy()
    {
        Auth::user()->beehiivConnection()->delete();

        return response()->json(['success' => true, 'message' => 'Beehiiv disconnected.']);
    }

    /** Recent Beehiiv posts for the link-placement picker (uses the stored key). */
    public function posts(BeehiivService $beehiiv)
    {
        $conn = Auth::user()->beehiivConnection;
        if (! $conn || ! $conn->publication_id) {
            return response()->json(['success' => false, 'message' => 'Connect Beehiiv first.'], 422);
        }

        try {
            $posts = $beehiiv->fetchPosts($conn->api_key, $conn->publication_id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => 'Beehiiv rejected the stored key — reconnect it.'], 422);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'Couldn’t reach Beehiiv right now.'], 502);
        }

        return response()->json(['success' => true, 'data' => $posts]);
    }

    private function payload(BeehiivConnection $conn, ?array $publications = null): array
    {
        return array_filter([
            'connected' => $conn->status === BeehiivConnection::STATUS_CONNECTED,
            'status' => $conn->status,
            'publication_id' => $conn->publication_id,
            'publication_name' => $conn->publication_name,
            'key_hint' => $conn->keyHint(),
            'last_validated_at' => optional($conn->last_validated_at)->toISOString(),
            'last_error' => $conn->last_error,
            'publications' => $publications, // only returned right after a connect, to allow switching
        ], fn ($v) => $v !== null);
    }
}
