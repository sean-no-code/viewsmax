<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\TrackingEventController;
use App\Http\Controllers\TrackingLinkController;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Create a tracking link')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class CreateTrackingLink extends ViewsMaxTool
{
    public function name(): string
    {
        return 'create_tracking_link';
    }

    public function description(): string
    {
        return 'Create a tracking link for an offer, to place in a video description, '
            . 'email, social bio, etc. Placement is one of: video, email, x, linkedin, '
            . 'podcast, blog, website, tiktok, ad, instagram, beehiiv, other — attaching '
            . 'a youtube_video_id or beehiiv_post_id auto-sets the matching placement. '
            . "Each call creates a new tracking link, so don't repeat a call that already succeeded.";
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->integer('tracking_event_id')->description('The offer id the link belongs to.')->required()
            ->string('placement')->description('Where the link will be placed.')->optional()
            ->string('name')->description('Display name.')->optional()
            ->string('description')->description('Notes about this link.')->optional()
            ->string('youtube_video_id')->description('Attach to one of the user\'s YouTube videos.')->optional()
            ->string('beehiiv_post_id')->description('Attach to one of the user\'s Beehiiv posts (from a connected Beehiiv account) for newsletter reach tracking.')->optional();
    }

    public function handle(array $arguments): ToolResult
    {
        return $this->callController(
            fn ($request) => app(TrackingLinkController::class)->store($request),
            $arguments,
            fn (array $link) => $link + ['url' => self::trackedUrl(
                (string) $this->user()->offers()->find($link['tracking_event_id'] ?? null)?->offer_url,
                (string) ($link['parameter_id'] ?? '')
            )]
        );
    }
}
