<?php

namespace App\Support;

/**
 * Whether services that cost money per call (Anthropic, Gemini, transcript
 * providers, …) talk to the real API. Production and staging always do.
 * local / development / testing return canned responses instead — so a dev
 * box and the test suite never spend — unless USE_LIVE_IN_APIS_DEVELOPMENT=true
 * is set to try a feature for real locally. Every provider service reads this
 * one switch; don't add per-service flags.
 */
final class LiveApis
{
    public static function enabled(): bool
    {
        if (! in_array(config('app.env'), ['local', 'development', 'testing'], true)) {
            return true;
        }

        return (bool) config('app.live_apis_in_development', false);
    }
}
