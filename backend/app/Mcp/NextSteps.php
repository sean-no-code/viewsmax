<?php

namespace App\Mcp;

/**
 * The `next_steps` text tools attach to their results: what the agent should
 * do next, written to be relayed to the user. Every outlier path leads to the
 * same goal, a drafted and scheduled post, because posting is the moment the
 * user gets value and the reason they signed up. The agent writes the post
 * script itself; no tool generates it.
 */
final class NextSteps
{
    public const POLL_SEARCH = 'Poll list_outliers with the same query every 10-15 seconds until its status '
        . 'is "done". Then show the top 5 by outlier_score (title, channel, views, score, link) and offer to '
        . 'break down the strongest one so you can write the user a post in the same style.';

    public const SEARCH_RUNNING = 'The scrape is still running. Poll list_outliers again in 10-15 seconds. '
        . 'Meanwhile ask which of their connected channels the post should go to (list_connected_accounts).';

    public const SEARCH_FAILED = 'The search failed. Run search_outliers again, or ask the user for a specific '
        . 'video URL and pull it in with fetch_outlier.';

    public const NO_RESULTS = 'No outliers match yet. Run search_outliers with this term (YouTube), broaden the '
        . 'term, or ask the user for a specific video URL and use fetch_outlier.';

    public const PICK_ONE = 'Show the top results with their outlier scores. Then call generate_outlier_breakdown '
        . 'on the strongest one (or the one the user picks) so you can write them a post in that style.';

    public const INGESTING = 'Poll get_outlier every 15 seconds (Instagram can take minutes) until status is '
        . '"ready", then call generate_outlier_breakdown.';

    public const READY_FOR_BREAKDOWN = 'Call generate_outlier_breakdown for this video, then poll '
        . 'get_outlier_breakdown until it is completed, so you can write the user a post in the same style.';

    public const BREAKDOWN_RUNNING = 'Poll get_outlier_breakdown every 10-15 seconds until status is "completed".';

    public const BREAKDOWN_NONE = 'No breakdown exists yet. Call generate_outlier_breakdown first.';

    public const BREAKDOWN_COMPLETED = 'Present the hook, the structure and why it over-performed in a few '
        . 'lines. Then write the user a post script in the same style for their own niche: hook, beats, '
        . 'caption and a title. Write it yourself from the payload; no tool produces it. Offer to schedule it: '
        . 'ask which connected accounts and what time (list_connected_accounts shows what is connected; if '
        . 'nothing is, give them the connect_page_url), then create_post with status "scheduled" and '
        . 'scheduled_at, or "draft" if they want to edit it in ViewsMax first. Video platforms need media '
        . 'uploaded with upload_media before scheduling.';

    public const SAVED = 'Saved to their library. Now offer to write a post script in this style and schedule '
        . 'it with create_post.';

    public const POST_DRAFT = 'Saved as a draft the user can edit in ViewsMax. To send it, call update_post with '
        . 'status "scheduled" and a scheduled_at they agree, or "posted" to publish now. Ask when it should '
        . 'go out.';

    public const POST_SCHEDULED = 'Tell the user when it goes out and on which platforms. Offer to pick the '
        . 'next outlier (list_outliers) and write the next post, so they build a queue.';

    public const POST_IN_PROGRESS = 'Wait a few seconds and call get_post. Do not tell the user it is live or '
        . 'failed until it says so.';

    public const POST_PUBLISHED = 'Tell the user it is live and where (targets[].platform_post_id). Offer to '
        . 'write and schedule the next post from another outlier.';

    public const POST_FAILED = 'Show the user targets[].error for each failed platform. If it says no account '
        . 'is connected, give them the Connections page (get_connect_url) and offer to retry once connected; '
        . 'otherwise offer to retry with update_post status "posted".';

    public static function breakdownFailed(?string $error): string
    {
        $noTranscript = $error !== null && stripos($error, 'transcript') !== false;

        return ($noTranscript
            ? 'This video has no transcript, so it cannot be broken down. '
            : 'The breakdown failed. Re-running generate_outlier_breakdown retries it once. ')
            . 'Otherwise pick another result from list_outliers, or ask the user for a video URL and use '
            . 'fetch_outlier, then continue towards writing and scheduling their post.';
    }

    public static function forBreakdown(array $breakdown): string
    {
        return match ($breakdown['status'] ?? null) {
            'completed' => self::BREAKDOWN_COMPLETED,
            'pending', 'processing' => self::BREAKDOWN_RUNNING,
            'failed' => self::breakdownFailed($breakdown['error'] ?? null),
            default => self::BREAKDOWN_NONE,
        };
    }

    public static function forPublishState(string $state): string
    {
        return match ($state) {
            'draft' => self::POST_DRAFT,
            'scheduled' => self::POST_SCHEDULED,
            'in_progress' => self::POST_IN_PROGRESS,
            'published' => self::POST_PUBLISHED,
            default => self::POST_FAILED,
        };
    }
}
