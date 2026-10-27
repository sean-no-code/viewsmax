<?php

namespace App\Services\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Revokes a Google OAuth grant when the user disconnects YouTube, so ViewsMax
 * drops out of their Google "third-party access" list instead of keeping a
 * live grant whose tokens we merely deleted. Best-effort: a failure is logged
 * and never blocks the disconnect, and a token Google no longer knows (already
 * revoked, expired) counts as done.
 */
final class GoogleGrantRevoker
{
    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    /**
     * Revoking either token kills the whole grant; the refresh token is
     * preferred because the access token may already have expired.
     *
     * @param  array<string, mixed>  $context  log context (who / which row)
     * @return bool whether Google no longer honours the grant
     */
    public static function revoke(?string $refreshToken, ?string $accessToken = null, array $context = []): bool
    {
        $token = $refreshToken ?: $accessToken;
        if (! $token) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (ConnectionException $e) {
            Log::warning('[google-revoke] request failed', $context + ['error' => $e->getMessage()]);

            return false;
        }

        if ($response->successful()) {
            Log::info('[google-revoke] grant revoked', $context);

            return true;
        }

        // 400 invalid_token: Google already forgot it (expired or revoked by
        // the user) — nothing is left to revoke. Anything else is worth a look.
        $gone = $response->status() === 400;
        Log::log($gone ? 'info' : 'warning', '[google-revoke] Google answered '.$response->status(), $context + [
            'error' => $response->json('error') ?? $response->body(),
        ]);

        return $gone;
    }
}
