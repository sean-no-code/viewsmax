<?php

namespace App\Http\Controllers;

use App\Http\Resources\SocialAccountResource;
use App\Models\SocialAccount;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * @group Connections
 *
 * Connected social accounts (YouTube, TikTok, X, LinkedIn, Threads,
 * Instagram, Bluesky, Facebook). List what's connected, start an OAuth
 * connect flow, or disconnect an account.
 */
class SocialAccountController extends Controller
{
    public function __construct(protected SocialProviderManager $manager) {}

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

        $provider = $this->manager->for($platform);

        if (! $provider->usesOAuth()) {
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

        $state = Str::random(40);
        $options = [];
        $stateData = [
            'user_id' => Auth::id(),
            'platform' => $platform,
            'redirect_uri' => $redirectUri,
        ];

        // X requires PKCE: keep the verifier server-side, send the challenge.
        if ($platform === 'x') {
            $verifier = Str::random(64);
            $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $options['code_challenge'] = $challenge;
            $stateData['code_verifier'] = $verifier;
        }

        // Stash state for CSRF protection + verifier retrieval at exchange time.
        Cache::put($this->stateKey($state), $stateData, now()->addMinutes(15));

        return response()->json([
            'success' => true,
            'data' => [
                'authorization_url' => $provider->getAuthorizationUrl($redirectUri, $state, $options),
                'state' => $state,
                'redirect_uri' => $redirectUri,
            ],
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

        $user = Auth::user();
        $redirectUri = $validated['redirect_uri'] ?? config('social.default_redirect_uri');
        $options = [];

        // Validate the state token and recover PKCE verifier / redirect URI.
        if (! empty($validated['state'])) {
            $stateData = Cache::pull($this->stateKey($validated['state']));

            if (! $stateData || ($stateData['user_id'] ?? null) !== $user->id || ($stateData['platform'] ?? null) !== $platform) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid or expired OAuth state.',
                ], 422);
            }

            $redirectUri = $stateData['redirect_uri'] ?? $redirectUri;
            if (! empty($stateData['code_verifier'])) {
                $options['code_verifier'] = $stateData['code_verifier'];
            }
        }

        try {
            $accounts = $this->manager->for($platform)
                ->connectFromCode($user, $validated['code'], $redirectUri, $options);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect '.$platform.': '.$e->getMessage(),
            ], 422);
        }

        // OAuth can succeed yet yield no usable account (e.g. an Instagram login
        // with no Business account linked to a Page). Report that as a failure so
        // the UI shows a real error instead of a misleading "connected".
        if ($accounts->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => $this->noAccountsMessage($platform),
            ], 422);
        }

        // A reconnect mints a new token: re-subscribe accounts that already
        // have live automations so comment/DM webhooks keep flowing. Best
        // effort — the daily automations:ensure-subscriptions catches misses.
        if ($platform === 'instagram' && config('social.platforms.instagram.automations_enabled')) {
            foreach ($accounts as $account) {
                if ($account->automations()->live()->exists()) {
                    try {
                        app(\App\Services\Automations\AutomationSubscriptionService::class)->ensureSubscribed($account, force: true);
                    } catch (Throwable $e) {
                        \App\Services\Automations\AutomationLog::warning('re-subscribe after reconnect failed', ['account_id' => $account->id, 'error' => $e->getMessage()]);
                    }
                }
            }
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

        $provider = $this->manager->for($platform);
        if ($provider->usesOAuth()) {
            return response()->json([
                'success' => false,
                'message' => ucfirst($platform).' connects via OAuth. Use the auth-url endpoint.',
            ], 422);
        }

        try {
            $accounts = $provider->connectWithCredentials(Auth::user(), $request->all());
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to connect '.$platform.': '.$e->getMessage(),
            ], 422);
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

    protected function stateKey(string $state): string
    {
        return 'social_oauth_state:'.$state;
    }

    /**
     * Human-friendly explanation when OAuth succeeds but no account is usable.
     */
    protected function noAccountsMessage(string $platform): string
    {
        return match ($platform) {
            'instagram' => 'Could not connect Instagram. Make sure you are logging in with an Instagram Business or Creator account.',
            'facebook' => 'No Facebook Page was found. You need to manage at least one Facebook Page and grant access to it.',
            default => 'No '.ucfirst($platform).' account could be connected. Check that you granted the requested permissions.',
        };
    }
}
