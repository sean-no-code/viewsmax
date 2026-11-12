<?php

namespace App\Support;

use App\Models\Post;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * The one answer to "may this user schedule or publish another post?".
 *
 * An account that hasn't paid yet — in the card-free signup window, or on the
 * $0 Stripe trial (`trialing`) — gets a fixed number of posts in total
 * (config publishing.trial_posts). The cap lifts when the subscription turns
 * `active`. Drafts are never counted.
 *
 *  - REST / MCP  PostController::validatePayload, when a request moves a post
 *                into scheduled or posted (create_post / update_post delegate
 *                there)
 *  - Legacy      SocialPostController::store
 *
 * Publishing itself is not gated: a post that is already scheduled goes out
 * (posts:publish-due, the publish jobs, retries), so the cap only ever refuses
 * new scheduling.
 *
 * Admins and promotional customers are exempt, matching UserAccess. Access
 * (UserAccess) is a separate question asked first by the middleware.
 */
final class PostAllowance
{
    /** Posts this user may schedule or publish in total; null = unlimited. */
    public static function cap(User $user): ?int
    {
        $cap = config('publishing.trial_posts');

        if ($cap === null || $cap === '') {
            return null;
        }

        if ($user->isExemptFromAccessRules() || $user->plans()->wherePivot('status', 'active')->exists()) {
            return null;
        }

        return (int) $cap;
    }

    /**
     * Posts counted against the cap: everything ever published (a counter, so
     * deleting a published post doesn't hand the slot back), what is scheduled
     * right now, and anything sent through the legacy social posts endpoint.
     */
    public static function used(User $user): int
    {
        return (int) $user->posts_published_count
            + $user->posts()->where('status', Post::STATUS_SCHEDULED)->count()
            + $user->socialPosts()->where('status', '<>', SocialPost::STATUS_DRAFT)->count();
    }

    /**
     * Null when the user may schedule or publish one more post, otherwise the
     * refusal to return (422).
     *
     * @return array{code: string, message: string, used: int, cap: int}|null
     */
    public static function refusal(User $user): ?array
    {
        $cap = self::cap($user);

        if ($cap === null) {
            return null;
        }

        $used = self::used($user);

        if ($used < $cap) {
            return null;
        }

        Log::channel('post_limit')->info('Post refused: trial post limit reached', [
            'user_id' => $user->id,
            'used' => $used,
            'cap' => $cap,
        ]);

        return [
            'code' => 'post_limit',
            // The last sentence is the one ViewsMaxTool::userFacingError strips
            // for AI clients, so the web app gets the nudge and MCP doesn't.
            'message' => "You've used all {$cap} posts included on your current plan. "
                .'Upgrade to access higher limits.',
            'used' => $used,
            'cap' => $cap,
        ];
    }
}
