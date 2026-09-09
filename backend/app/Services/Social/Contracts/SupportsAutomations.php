<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;
use App\Services\Social\Data\CommentResult;
use App\Services\Social\Data\MessageResult;

/**
 * Providers that can drive comment / story-reply / DM automations: receive
 * inbound events (webhooks), list the account's own media for the post
 * picker, reply publicly to a comment and send DMs. Instagram implements the
 * full set; a future TikTok provider may implement a subset (comments only).
 */
interface SupportsAutomations
{
    /** Subscribe this account to inbound webhook fields. */
    public function subscribeWebhooks(SocialAccount $account, array $fields = ['comments', 'messages']): bool;

    public function unsubscribeWebhooks(SocialAccount $account): bool;

    /**
     * The account's own media, newest first, for the post picker.
     *
     * @return array{items: array<int, array<string, mixed>>, next_cursor: ?string}
     */
    public function listMedia(SocialAccount $account, ?string $after = null, int $limit = 24): array;

    /** Public reply under a comment. */
    public function replyToComment(SocialAccount $account, string $commentId, string $text): CommentResult;

    /** DM to a user who has messaged the account (24h window). */
    public function sendMessage(SocialAccount $account, string $recipientId, array $message): MessageResult;

    /** One DM to the author of a comment (allowed once per comment, 7 days). */
    public function sendPrivateReply(SocialAccount $account, string $commentId, array $message): MessageResult;
}
