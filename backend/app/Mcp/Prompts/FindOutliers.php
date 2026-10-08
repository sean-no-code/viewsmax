<?php

namespace App\Mcp\Prompts;

use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;
use Laravel\Mcp\Server\Prompts\Arguments;
use Laravel\Mcp\Server\Prompts\PromptResult;

/**
 * Slash-command entry point ("find-outliers") that walks the agent through the
 * same outliers-first flow the server instructions open with. Needs nothing
 * connected, so it is the fastest way for a new user to get value.
 */
class FindOutliers extends Prompt
{
    protected string $description = 'Find outlier videos — content that massively over-performed its '
        . 'channel — for a niche, break down why the best one worked, then write the user a post in that '
        . 'style and offer to schedule it. Works with no accounts connected.';

    public function arguments(): Arguments
    {
        return (new Arguments)
            ->add(new Argument(
                name: 'niche',
                description: 'Topic or niche to search, e.g. "personal finance". Leave empty for the featured feed.',
                required: false,
            ))
            ->add(new Argument(
                name: 'platform',
                description: 'Limit to one platform: youtube, tiktok or instagram. Leave empty for all.',
                required: false,
            ));
    }

    public function handle(array $arguments): PromptResult
    {
        $niche = trim((string) ($arguments['niche'] ?? ''));
        $platform = strtolower(trim((string) ($arguments['platform'] ?? '')));
        $platform = in_array($platform, ['youtube', 'tiktok', 'instagram'], true) ? $platform : '';

        $scope = ($niche !== '' ? " in \"{$niche}\"" : '') . ($platform !== '' ? " on {$platform}" : '');
        $filter = $platform !== '' ? " with platform \"{$platform}\"" : '';

        $find = $niche === ''
            ? "1. Call list_outliers with no query{$filter} to get the featured feed."
            : ($platform === '' || $platform === 'youtube'
                ? "1. Call search_outliers with term \"{$niche}\", then poll list_outliers with query \"{$niche}\"{$filter} "
                    . 'every 10-15 seconds until its status is "done".'
                : "1. Call list_outliers with query \"{$niche}\"{$filter} (search_outliers covers YouTube only).");

        $content = "Use ViewsMax to find outlier videos{$scope} and explain why they worked.\n"
            . $find . "\n"
            . "2. Show the top 5 by outlier_score: title, channel, views, outlier score and link.\n"
            . '3. For the strongest one, call generate_outlier_breakdown, then poll get_outlier_breakdown '
            . "until status is completed. Summarise the hook, the structure, why it over-performed, and how I could adapt it.\n"
            . "4. Then ask would you like me to write a script in that style for your own niche or specific idea: hook, beats, caption and title.\n"
            . '5. Offer to schedule it: check list_connected_accounts, ask which accounts and what time, then '
            . 'create_post with status "scheduled" (or "draft" if I want to edit it first).';

        return new PromptResult(
            content: $content,
            description: 'Outlier research' . $scope,
        );
    }
}
