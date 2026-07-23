<?php

namespace App\Http\Controllers;

use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Meta platform lifecycle webhooks (Threads / Facebook / Instagram).
 *
 * Meta calls these when a user removes the app ("deauthorize") or requests
 * deletion of their data ("data deletion"). Both arrive as a POST carrying a
 * `signed_request` — a base64url payload signed (HMAC-SHA256) with the app
 * secret. These endpoints are public: the signature IS the authentication.
 *
 * The data-deletion endpoint must respond with JSON `{ url, confirmation_code }`
 * per Meta's spec so the user can track the request.
 */
class SocialWebhookController extends Controller
{
    /**
     * Platforms that authenticate webhooks with a Meta signed_request.
     */
    protected const META_PLATFORMS = ['threads', 'facebook', 'instagram'];

    /**
     * App uninstalled / permissions revoked by the user.
     */
    public function deauthorize(Request $request, string $platform)
    {
        $data = $this->parseSignedRequest($request, $platform);
        $userId = $data['user_id'] ?? null;

        if ($userId) {
            $deleted = $this->forgetAccounts($platform, (string) $userId);
            Log::info('[SocialWebhook] deauthorize', compact('platform', 'userId') + ['accounts_removed' => $deleted]);
        } else {
            Log::info('[SocialWebhook] deauthorize ping (no signed_request)', compact('platform'));
        }

        return response()->json(['success' => true]);
    }

    /**
     * User requested deletion of their data. Must return a status URL + code.
     */
    public function dataDeletion(Request $request, string $platform)
    {
        $data = $this->parseSignedRequest($request, $platform);
        $userId = $data['user_id'] ?? null;

        $code = strtoupper(Str::random(16));

        if ($userId) {
            $deleted = $this->forgetAccounts($platform, (string) $userId);
            Log::info('[SocialWebhook] data deletion', compact('platform', 'userId', 'code') + ['accounts_removed' => $deleted]);
        } else {
            Log::info('[SocialWebhook] data deletion ping (no signed_request)', compact('platform', 'code'));
        }

        $base = rtrim(config('app.frontend_url') ?: config('app.url'), '/');

        return response()->json([
            'url' => "{$base}/data-deletion?platform={$platform}&code={$code}",
            'confirmation_code' => $code,
        ]);
    }

    /**
     * Delete every stored account for this platform + Meta user id.
     */
    protected function forgetAccounts(string $platform, string $platformAccountId): int
    {
        return SocialAccount::query()
            ->where('platform', $platform)
            ->where('platform_account_id', $platformAccountId)
            ->delete();
    }

    /**
     * Verify and decode a Meta signed_request. Returns [] when absent (a
     * reachability ping) and null-safe fields when the signature is invalid.
     *
     * @return array<string, mixed>
     */
    protected function parseSignedRequest(Request $request, string $platform): array
    {
        $signedRequest = $request->input('signed_request');
        if (! $signedRequest || ! str_contains($signedRequest, '.')) {
            return [];
        }

        $secret = config("social.platforms.{$platform}.client_secret");
        if (! $secret) {
            Log::warning('[SocialWebhook] no app secret configured', compact('platform'));

            return [];
        }

        [$encodedSig, $payload] = explode('.', $signedRequest, 2);

        $sig = $this->base64UrlDecode($encodedSig);
        $expected = hash_hmac('sha256', $payload, $secret, true);

        if (! hash_equals($expected, $sig)) {
            Log::warning('[SocialWebhook] invalid signed_request signature', compact('platform'));

            return [];
        }

        $data = json_decode($this->base64UrlDecode($payload), true);

        return is_array($data) ? $data : [];
    }

    protected function base64UrlDecode(string $input): string
    {
        return (string) base64_decode(strtr($input, '-_', '+/'));
    }
}
