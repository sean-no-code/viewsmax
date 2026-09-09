<?php

namespace App\Services\Automations;

/**
 * Meta signs every webhook POST with X-Hub-Signature-256 = "sha256=" +
 * HMAC-SHA256(raw body, app secret). Verification MUST run over the raw
 * request body — re-encoding the JSON changes key order / escaping.
 */
class InstagramWebhookSignature
{
    public function verify(string $rawBody, ?string $header): bool
    {
        if (config('social.platforms.instagram.webhook_signature_check', true) === false) {
            return true;
        }

        $secret = (string) config('social.platforms.instagram.client_secret');
        if ($secret === '' || ! $header) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, trim($header));
    }

    /** The header value Meta would send for $rawBody (used by tests). */
    public static function sign(string $rawBody, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $rawBody, $secret);
    }
}
