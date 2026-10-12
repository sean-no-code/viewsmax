<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * One per-user hourly budget for every action that spends Anthropic tokens
 * (script generate/refine, outlier breakdowns, title/thumbnail review).
 * Call hit() right where the work is about to be queued — after cache hits
 * have been served — so REST and MCP (whose tools call the same controller
 * methods) share the same pool and ordinary reads/edits never count.
 *
 * services.anthropic.user_calls_per_hour (ANTHROPIC_USER_CALLS_PER_HOUR):
 * 0 turns the limit off. Logs to storage/logs/ai-usage.log (AI_USAGE_LOG_ENABLED).
 */
final class AnthropicRateLimit
{
    private const DECAY_SECONDS = 3600;

    /**
     * Count one call for $user, or return the 429 to send when the budget is
     * spent. Fails open: a broken cache must not take AI features down.
     */
    public static function hit(User $user, string $action): ?JsonResponse
    {
        $max = (int) config('services.anthropic.user_calls_per_hour', 30);
        if ($max <= 0) {
            return null;
        }

        $key = 'anthropic:'.$user->id;

        try {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = RateLimiter::availableIn($key);
                Log::channel('ai_usage')->warning('limited', ['user_id' => $user->id, 'action' => $action, 'max_per_hour' => $max, 'retry_after' => $retryAfter]);

                return response()->json([
                    'success' => false,
                    'code' => 'ai_rate_limited',
                    'message' => "You've reached the AI limit of {$max} requests per hour. Try again in ".max(1, (int) ceil($retryAfter / 60)).' minutes.',
                    'retry_after' => $retryAfter,
                ], 429, ['Retry-After' => (string) $retryAfter]);
            }

            RateLimiter::hit($key, self::DECAY_SECONDS);
            Log::channel('ai_usage')->info('allowed', ['user_id' => $user->id, 'action' => $action, 'remaining' => RateLimiter::remaining($key, $max)]);
        } catch (Throwable $e) {
            Log::warning('Anthropic rate limit unavailable; allowing call', ['user_id' => $user->id, 'action' => $action, 'exception' => $e]);
        }

        return null;
    }
}
