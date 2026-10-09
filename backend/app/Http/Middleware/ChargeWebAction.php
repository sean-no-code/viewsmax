<?php

namespace App\Http\Middleware;

use App\Models\Post;
use App\Models\User;
use App\Services\CreditService;
use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges a website action the same credits as the matching AI (MCP) tool:
 * `credits.web:<tool>` reads the price from config/credits.php `mcp`, the
 * list SafeCallTool uses, so the two never drift apart.
 *
 * Same rules as the AI: refused with 402 before anything runs when the balance
 * is below the price, charged only after a successful (2xx) response, never
 * below zero. Uploading media in the composer is free: the post is charged
 * when it is published or scheduled.
 *
 * AI tools call the controllers directly, not through these routes, so an AI
 * action is never charged twice. A post is charged when it is published or
 * scheduled, not when saved as a draft, and only once.
 */
class ChargeWebAction
{
    /** What the refusal message calls each action. */
    private const LABELS = [
        'create_post' => 'publish a post',
        'search_outliers' => 'search outliers',
        'fetch_outlier' => 'fetch an outlier',
        'generate_outlier_breakdown' => 'generate an AI breakdown',
        'add_outlier_channel' => 'add an outlier channel',
    ];

    public function __construct(private CreditService $credits) {}

    public function handle(Request $request, Closure $next, string $tool): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->charges($request, $tool, $user)) {
            return $next($request);
        }

        $cost = $this->credits->mcpToolCost($tool, true);

        if ($cost <= 0) {
            return $next($request);
        }

        if ($user->balanceInt < $cost) {
            Log::channel('credits')->info('Web action refused: insufficient credits', [
                'user_id' => $user->id, 'tool' => $tool, 'cost' => $cost, 'balance' => $user->balanceInt,
            ]);

            return response()->json([
                'success' => false,
                'code' => 'insufficient_credits',
                'message' => sprintf(
                    'Not enough credits to %s (%d needed, %d available). Choose a plan to get more credits.',
                    self::LABELS[$tool] ?? $tool, $cost, $user->balanceInt,
                ),
                'required' => $cost,
                'available' => $user->balanceInt,
            ], 402);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $this->charge($user, $tool, $cost);
        }

        return $response;
    }

    /**
     * Posts are charged when they go out (published or scheduled), once: saving
     * a draft is free, and editing a post that was already published or
     * scheduled doesn't charge again. Every other action is always charged.
     */
    private function charges(Request $request, string $tool, User $user): bool
    {
        if ($tool !== 'create_post') {
            return true;
        }

        $goesOut = in_array($request->input('status'), [Post::STATUS_SCHEDULED, Post::STATUS_POSTED], true);
        $existing = $request->route('post');

        if ($existing === null) {
            return $goesOut;
        }

        $post = $user->posts()->find($existing);

        return $goesOut && $post?->status === Post::STATUS_DRAFT;
    }

    private function charge(User $user, string $tool, int $cost): void
    {
        try {
            $user->withdraw($cost, ['description' => 'Website: ' . (self::LABELS[$tool] ?? $tool), 'type' => 'web_action', 'tool' => $tool]);
        } catch (InsufficientFunds|BalanceIsEmpty) {
            // The balance changed while the action ran; the work is done, so
            // skip the charge rather than go negative (same as the AI).
            Log::channel('credits')->warning('Web charge skipped: balance changed mid-request', [
                'user_id' => $user->id, 'tool' => $tool, 'cost' => $cost, 'balance' => $user->balanceInt,
            ]);

            return;
        }

        Log::channel('credits')->info('Web action charged', [
            'user_id' => $user->id, 'tool' => $tool, 'cost' => $cost, 'balance_after' => $user->balanceInt,
        ]);
    }
}
