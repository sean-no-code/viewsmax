<?php

namespace App\Http\Middleware;

use App\Models\Post;
use App\Models\User;
use App\Services\CreditService;
use App\Support\XLinkCharge;
use Bavix\Wallet\Exceptions\BalanceIsEmpty;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Charges a website action its price from config/credits.php `web`:
 * `credits.web:<action>` on a route, named after the matching AI tool where
 * there is one. `credits.web:read` sits on the whole logged-in group and
 * prices every GET (read_default, 0 unless changed).
 *
 * Same rules as the AI: refused with 402 before anything runs when the balance
 * is below the price, charged only after a successful (2xx) response, never
 * below zero. A price of 0 does nothing.
 *
 * Posts: creating one costs create_post (drafts included) and every edit costs
 * update_post, like the AI. Images and videos cost upload_media each, but when
 * the post is published or scheduled rather than at upload, so swapping
 * images in the composer is free; a file is only ever charged once. Each X
 * post with a link adds x_link per X account (App\Support\XLinkCharge).
 *
 * AI tools call the controllers directly, not through these routes, so an AI
 * action is never charged twice.
 */
class ChargeWebAction
{
    /** What the refusal message calls each action. */
    private const LABELS = [
        'create_post' => 'create a post',
        'update_post' => 'edit a post',
        'delete_post' => 'delete a post',
        'retry_post' => 'retry a post',
        'search_outliers' => 'search outliers',
        'fetch_outlier' => 'fetch an outlier',
        'generate_outlier_breakdown' => 'generate an AI breakdown',
        'add_outlier_channel' => 'add an outlier channel',
        'save_outlier' => 'save an outlier',
        'update_saved_outlier' => 'edit a saved outlier',
        'remove_saved_outlier' => 'remove a saved outlier',
        'saved_filter' => 'save a filter',
        'competitor' => 'change your competitors',
        'refresh_outlier_media' => 'refresh outlier media',
        'create_offer' => 'create an offer',
        'update_offer' => 'edit an offer',
        'delete_offer' => 'delete an offer',
        'create_tracking_link' => 'create a tracking link',
        'update_tracking_link' => 'edit a tracking link',
        'delete_tracking_link' => 'delete a tracking link',
        'get_connect_url' => 'connect an account',
        'disconnect_account' => 'disconnect an account',
        'create_feature_request' => 'send a feature request',
        'upvote_feature_request' => 'upvote a feature request',
        'read' => 'view this page',
    ];

    public function __construct(private CreditService $credits) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ($action === 'read' && ! $this->isPricedRead($request))) {
            return $next($request);
        }

        $isPost = in_array($action, ['create_post', 'update_post'], true);
        $media = $isPost ? $this->mediaToCharge($request, $user) : 0;
        $xLinks = $isPost ? XLinkCharge::count($user, $request->all(), $this->savedPost($request, $user)) : 0;
        $cost = $this->credits->webActionCost($action)
            + $media * $this->credits->webActionCost('upload_media')
            + $xLinks * $this->credits->webActionCost('x_link');

        if ($cost <= 0) {
            return $next($request);
        }

        $label = $this->label($action, $media, $xLinks);

        if ($user->balanceInt < $cost) {
            Log::channel('credits')->info('Web action refused: insufficient credits', [
                'user_id' => $user->id, 'tool' => $action, 'cost' => $cost, 'balance' => $user->balanceInt,
            ]);

            return response()->json([
                'success' => false,
                'code' => 'insufficient_credits',
                'message' => sprintf(
                    'Not enough credits to %s (%d needed, %d available). Choose a plan to get more credits.',
                    $label, $cost, $user->balanceInt,
                ),
                'required' => $cost,
                'available' => $user->balanceInt,
            ], 402);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $this->charge($user, $action, $cost, $label);
        }

        return $response;
    }

    /** Reads are GETs, minus what a locked user still needs (profile, plans, billing). */
    private function isPricedRead(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->is(...EnsureAccessActive::ALLOWED_PATTERNS);
    }

    /**
     * Images and videos to charge for: those in the post when it is published
     * or scheduled, minus any already charged (it was already out with them).
     * A draft charges none yet.
     */
    private function savedPost(Request $request, User $user): ?Post
    {
        $existing = $request->route('post');

        return $existing === null ? null : $user->posts()->with('targets', 'comments')->find($existing);
    }

    private function mediaToCharge(Request $request, User $user): int
    {
        $post = $this->savedPost($request, $user);

        $status = $request->input('status', $post?->status ?? Post::STATUS_DRAFT);
        if (! in_array($status, [Post::STATUS_SCHEDULED, Post::STATUS_POSTED], true)) {
            return 0;
        }

        $media = is_array($request->input('media')) ? $request->input('media') : ($post?->media ?? []);
        $alreadyOut = $post !== null && $post->status !== Post::STATUS_DRAFT;
        $charged = $alreadyOut && is_array($post->media) ? count($post->media) : 0;

        return max(0, count($media) - $charged);
    }

    private function label(string $action, int $media, int $xLinks = 0): string
    {
        $parts = array_filter([
            $media > 0 ? sprintf('%d %s', $media, $media === 1 ? 'image or video' : 'images or videos') : null,
            $xLinks > 0 ? ($xLinks === 1 ? 'a link on X' : sprintf('links on X (%d X posts)', $xLinks)) : null,
        ]);
        $label = self::LABELS[$action] ?? $action;

        return $parts === [] ? $label : $label . ' with ' . implode(' and ', $parts);
    }

    private function charge(User $user, string $action, int $cost, string $label): void
    {
        try {
            $user->withdraw($cost, ['description' => 'Website: ' . $label, 'type' => 'web_action', 'tool' => $action]);
        } catch (InsufficientFunds|BalanceIsEmpty) {
            // The balance changed while the action ran; the work is done, so
            // skip the charge rather than go negative (same as the AI).
            Log::channel('credits')->warning('Web charge skipped: balance changed mid-request', [
                'user_id' => $user->id, 'tool' => $action, 'cost' => $cost, 'balance' => $user->balanceInt,
            ]);

            return;
        }

        Log::channel('credits')->info('Web action charged', [
            'user_id' => $user->id, 'tool' => $action, 'cost' => $cost, 'balance_after' => $user->balanceInt,
        ]);
    }
}
