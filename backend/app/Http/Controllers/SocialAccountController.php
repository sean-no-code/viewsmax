<?php

namespace App\Http\Controllers;

use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\Social\SocialConnect;
use App\Services\Social\SocialConnectException;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Connections
 *
 * Connected social accounts (YouTube, TikTok, X, LinkedIn, Threads,
 * Instagram, Bluesky, Facebook). List what's connected, start an OAuth
 * connect flow, or disconnect an account.
 */
class SocialAccountController extends Controller
{
    public function __construct(protected SocialProviderManager $manager, protected SocialConnect $connect) {}

    /**
     * List every platform the app supports plus whether it is configured.
     */
    public function platforms()
    {
        return response()->json([
            'success' => true,
            'data' => $this->manager->catalog(),
        ]);
    }

    /**
     * List the authenticated user's connected accounts (optionally by platform).
     */
    public function index(Request $request)
    {
        $query = Auth::user()->socialAccounts()->latest();

        if ($request->filled('platform')) {
            $query->where('platform', $request->string('platform'));
        }

        return SocialAccountResource::collection($query->get())
            ->additional(['success' => true]);
    }

    /**
     * Build the OAuth authorization URL for a platform. The frontend opens this,
     * the user grants access, and the provider redirects back to redirect_uri
     * with a `code` to be sent to exchange().
     */
    public function authUrl(Request $request, string $platform)
    {
        if (! $this->ensureConfigured($platform, $response)) {
            return $response;
        }

        if (! $this->manager->for($platform)->usesOAuth()) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($platform).' does not use OAuth. Use the connect endpoint instead.',
            ], 422);
        }

        $redirectUri = $request->input('redirect_uri', config('social.default_redirect_uri'));

        Log::info('[SocialAuth] auth-url requested', [
            'platform' => $platform,
            'redirect_uri_from_frontend' => $request->input('redirect_uri'),
            'redirect_uri_used' => $redirectUri,
            'origin' => $request->header('origin'),
        ]);

        if (! $redirectUri) {
            return response()->json([
                'success' => false,
                'message' => 'A redirect_uri is required (set FRONTEND_URL or pass redirect_uri).',
            ], 422);
        }

        $built = $this->connect->authorizationUrl(Auth::user(), $platform, $redirectUri, $request->boolean('follow_us'));

        return response()->json([
            'success' => true,
            'data' => $built + ['redirect_uri' => $redirectUri],
        ]);
    }

    /**
     * Exchange an authorization code (returned to the frontend callback) for
     * tokens and persist the connected account(s).
     */
    public function exchange(Request $request, string $platform)
    {
        $validated = $request->validate([
            'code' => 'required|string',
            'state' => 'nullable|string',
            'redirect_uri' => 'nullable|string|url',
        ]);

        if (! $this->ensureConfigured($platform, $response)) {
            return $response;
        }

        try {
            $accounts = $this->connect->complete(Auth::user(), $platform, $validated['code'], $validated['state'] ?? null, $validated['redirect_uri'] ?? null);
        } catch (SocialConnectException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $accounts->count().' '.$platform.' account(s) connected.',
            'data' => SocialAccountResource::collection($accounts),
        ]);
    }

    /**
     * Connect a non-OAuth platform (e.g. Bluesky) with direct credentials.
     */
    public function connectWithCredentials(Request $request, string $platform)
    {
        if (! $this->manager->supports($platform)) {
            return response()->json(['success' => false, 'message' => 'Unsupported platform.'], 404);
        }

        if ($this->manager->for($platform)->usesOAuth()) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($platform).' connects via OAuth. Use the auth-url endpoint.',
            ], 422);
        }

        try {
            $accounts = $this->connect->connectWithCredentials(Auth::user(), $platform, $request->all(), $request->boolean('follow_us'));
        } catch (SocialConnectException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => ucfirst($platform).' account connected.',
            'data' => SocialAccountResource::collection($accounts),
        ]);
    }

    /**
     * Disconnect (delete) a connected account.
     */
    public function destroy(int $id)
    {
        $account = Auth::user()->socialAccounts()->findOrFail($id);
        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Account disconnected.',
        ]);
    }

    protected function ensureConfigured(string $platform, &$response): bool
    {
        if (! $this->manager->supports($platform)) {
            $response = response()->json(['success' => false, 'message' => 'Unsupported platform.'], 404);

            return false;
        }

        if (! $this->manager->isConfigured($platform)) {
            $response = response()->json([
                'success' => false,
                'message' => ucfirst($platform).' is not configured. Add its API credentials to the environment.',
            ], 503);

            return false;
        }

        return true;
    }
}
