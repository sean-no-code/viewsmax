<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;
use App\Services\Social\Data\CommentResult;

/**
 * Providers that can post a comment/reply on an already-published post.
 * Capability is checked with `instanceof` — platforms without a comment API
 * (TikTok; YouTube pending scope work) simply don't implement this.
 */
interface SupportsComments
{
    /**
     * Post $body as a comment/reply on the published post $remotePostId.
     * $previousRemoteId is the id of the previous comment in this chain —
     * threading platforms (X, Threads) reply to it so the chain stays intact;
     * flat-comment platforms (LinkedIn, Instagram) ignore it.
     */
    public function comment(SocialAccount $account, string $remotePostId, string $body, ?string $previousRemoteId = null): CommentResult;
}
