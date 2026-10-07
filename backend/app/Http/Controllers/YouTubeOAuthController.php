<?php

namespace App\Http\Controllers;

use App\Exceptions\AccountAlreadyConnectedException;
use App\Models\Channel;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\YouTubeAnalyticsService;
use App\Services\YouTubePublishService;
use App\Services\YouTubeChannelService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class YouTubeOAuthController extends Controller
{
    /**
     * Exchange authorization code for access and refresh tokens
     */
    public function exchange(Request $request)
    {
        // Store tokens for the authenticated user
        $user = Auth::user();

        // Debug logging
        Log::info('YouTube OAuth exchange debug', [
            'user' => $user ? $user->id : 'null',
            'auth_guard' => Auth::getDefaultDriver(),
            'request_headers' => $request->headers->all(),
            'bearer_token' => $request->bearerToken(),
        ]);

        if (! $user) {
            Log::error('No authenticated user found during YouTube OAuth exchange', [
                'bearer_token' => $request->bearerToken(),
                'authorization_header' => $request->header('Authorization'),
                'all_headers' => $request->headers->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'User not authenticated',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
            'redirect_uri' => 'required|string|url',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');

            if (! $clientId || ! $clientSecret) {
                Log::error('Google OAuth credentials not configured');

                return response()->json([
                    'success' => false,
                    'message' => 'OAuth service not configured',
                ], 500);
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'code' => $request->code,
                'grant_type' => 'authorization_code',
                'redirect_uri' => $request->redirect_uri,
            ]);

            if (! $response->successful()) {
                Log::error('Google OAuth token exchange failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to exchange authorization code',
                    'error' => $response->json(),
                ], 400);
            }

            $tokenData = $response->json();

            // Put back on refusal: a blocked channel must leave the account unchanged.
            $previousTokens = $user->only(['youtube_access_token', 'youtube_refresh_token', 'youtube_token_expires_at']);

            $user->update([
                'youtube_access_token' => $tokenData['access_token'],
                'youtube_refresh_token' => $tokenData['refresh_token'] ?? null,
                'youtube_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            ]);

            $channelConnected = false;
            $connectionData = null;

            // Fetch channel data, create/update channel, and fetch analytics
            $analyticsService = new YouTubeAnalyticsService;
            $channelService = new YouTubeChannelService($analyticsService);

            try {
                $channel = $channelService->createOrUpdateChannel(
                    $user,
                    $tokenData['access_token'],
                    null, // channelId - null means use mine=true
                    $tokenData // tokenData for OAuth exchange
                );

                Log::info('Channel connected and stored', [
                    'user_id' => $user->id,
                    'channel_id' => $channel->id,
                    'youtube_channel_id' => $channel->youtube_channel_id,
                ]);

                $channelConnected = true;

                // Mirror the channel into the generic connections table so it counts
                // toward connections_count and appears in GET /connections.
                $connection = \App\Models\Connection::reconnect(
                    ['user_id' => $user->id, 'provider' => 'youtube'],
                    [
                        'account_name' => $channel->channel_name ?? 'YouTube Channel',
                        'account_id' => $channel->youtube_channel_id,
                        'avatar_url' => $channel->profile_image_url,
                        'access_token' => $tokenData['access_token'],
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'token_expires_at' => now()->addSeconds($tokenData['expires_in']),
                    ]
                );
                $connectionData = $connection->toApiArray();

                // Multi-account bridge: mirror this channel into the `social_accounts`
                // store (keyed on the channel id, so a different channel/login ADDS a
                // row) — that's what YouTube publishing now reads.
                if ($channel->youtube_channel_id) {
                    $this->mirrorIntoSocialAccounts($user, $channel, $tokenData);
                }

                // Fetch and import videos after channel creation
                try {
                    $channelService->fetchAndImportChannelVideos(
                        $channel,
                        $tokenData['access_token']
                    );
                } catch (\Exception $videoError) {
                    // Log but don't fail the OAuth exchange if video fetching fails
                    Log::warning('Failed to fetch videos during OAuth exchange', [
                        'channel_id' => $channel->id,
                        'error' => $videoError->getMessage(),
                    ]);
                }

            } catch (AccountAlreadyConnectedException $e) {
                throw $e; // handled below: refuse the whole connect
            } catch (\Exception $e) {
                Log::error('Failed to fetch/store channel data', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            Log::info('YouTube OAuth tokens exchanged successfully', [
                'user_id' => $user->id,
                'has_refresh_token' => ! empty($tokenData['refresh_token']),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Authorization code exchanged successfully',
                'data' => [
                    'access_token' => $tokenData['access_token'],
                    'expires_in' => $tokenData['expires_in'],
                    'token_type' => $tokenData['token_type'] ?? 'Bearer',
                    'scope' => $tokenData['scope'] ?? null,
                    'channel_connected' => $channelConnected,
                    'connection' => $connectionData,
                ],
            ]);

        } catch (AccountAlreadyConnectedException $e) {
            $user->forceFill($previousTokens)->save();

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            Log::error('YouTube OAuth exchange failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to exchange authorization code',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Mirror a Google grant into the multi-account store that YouTube publishing
     * reads. Records the scopes Google actually granted (the Settings/Analytics
     * connect asks for read + analytics only), and never replaces a token that
     * can upload with one that can't: a user who reconnected from Analytics
     * used to lose publishing silently while the row still claimed the upload
     * scope, so it only surfaced as a 403 at post time. Google only returns a
     * refresh_token on a consent grant, so the stored one is kept otherwise.
     *
     * @param  array<string, mixed>  $tokenData  Google's token response
     */
    private function mirrorIntoSocialAccounts(User $user, Channel $channel, array $tokenData): void
    {
        $granted = array_values(array_filter(explode(' ', (string) ($tokenData['scope'] ?? ''))));
        $canUpload = in_array(YouTubePublishService::UPLOAD_SCOPE, $granted, true);
        $key = ['user_id' => $user->id, 'platform' => 'youtube', 'platform_account_id' => $channel->youtube_channel_id];
        $profile = [
            'name' => $channel->channel_name ?? 'YouTube Channel',
            'avatar_url' => $channel->profile_image_url,
            'last_synced_at' => now(),
        ];

        $existing = SocialAccount::where($key)->first(); // a disconnected row is reconnected below
        if ($existing && ! $canUpload
            && $existing->status === SocialAccount::STATUS_CONNECTED
            && $existing->refresh_token
            && in_array(YouTubePublishService::UPLOAD_SCOPE, (array) $existing->scopes, true)) {
            $existing->update($profile);
            Log::warning('YouTube grant without the upload scope; kept the existing publishing token', [
                'user_id' => $user->id,
                'social_account_id' => $existing->id,
                'granted' => $granted,
            ]);

            return;
        }

        $attrs = $profile + [
            'access_token' => $tokenData['access_token'],
            'token_expires_at' => now()->addSeconds($tokenData['expires_in'] ?? 3600),
            'status' => SocialAccount::STATUS_CONNECTED,
            'scopes' => $granted ?: config('social.platforms.youtube.scopes', []),
            'metadata' => ['channel_id' => $channel->youtube_channel_id],
        ];
        if (! empty($tokenData['refresh_token'])) {
            $attrs['refresh_token'] = $tokenData['refresh_token'];
        }

        SocialAccount::reconnect($key, $attrs);
    }

    /**
     * Refresh access token using refresh token
     */
    public function refresh(Request $request)
    {
        try {
            $user = Auth::user();

            if (! $user) {
                Log::error('No authenticated user found during YouTube OAuth refresh');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            if (! $user->youtube_refresh_token) {
                return response()->json([
                    'success' => false,
                    'message' => 'No refresh token available',
                ], 400);
            }

            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');

            if (! $clientId || ! $clientSecret) {
                Log::error('Google OAuth credentials not configured');

                return response()->json([
                    'success' => false,
                    'message' => 'OAuth service not configured',
                ], 500);
            }

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'refresh_token' => $user->youtube_refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                Log::error('Google OAuth token refresh failed', [
                    'user_id' => $user->id,
                    'status' => $response->status(),
                    'response' => $response->body(),
                ]);

                // If refresh token is invalid, clear stored tokens
                if ($response->status() === 400) {
                    $user->update([
                        'youtube_access_token' => null,
                        'youtube_refresh_token' => null,
                        'youtube_token_expires_at' => null,
                    ]);
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Failed to refresh access token',
                    'error' => $response->json(),
                ], 400);
            }

            $tokenData = $response->json();

            // Update stored tokens
            $user->update([
                'youtube_access_token' => $tokenData['access_token'],
                'youtube_token_expires_at' => now()->addSeconds($tokenData['expires_in']),
            ]);

            Log::info('YouTube OAuth token refreshed successfully', [
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Access token refreshed successfully',
                'data' => [
                    'access_token' => $tokenData['access_token'],
                    'expires_in' => $tokenData['expires_in'],
                    'token_type' => $tokenData['token_type'] ?? 'Bearer',
                    'scope' => $tokenData['scope'] ?? null,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube OAuth refresh failed', [
                'user_id' => $user->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to refresh access token',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current YouTube OAuth status for the user
     */
    public function status(Request $request)
    {
        try {
            $user = Auth::user();

            if (! $user) {
                Log::error('No authenticated user found during YouTube OAuth status check');

                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated',
                ], 401);
            }

            $hasValidToken = $user->youtube_access_token &&
                           $user->youtube_token_expires_at &&
                           $user->youtube_token_expires_at->isFuture();

            return response()->json([
                'success' => true,
                'data' => [
                    'has_access_token' => ! empty($user->youtube_access_token),
                    'has_refresh_token' => ! empty($user->youtube_refresh_token),
                    'token_expires_at' => $user->youtube_token_expires_at?->toISOString(),
                    'is_token_valid' => $hasValidToken,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('YouTube OAuth status check failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check OAuth status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
