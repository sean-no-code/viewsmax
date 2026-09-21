<?php

namespace App\Mcp;

use App\Mcp\Methods\SafeCallTool;
use App\Mcp\Tools\CreateFeatureRequest;
use App\Mcp\Tools\CreateOffer;
use App\Mcp\Tools\CreatePost;
use App\Mcp\Tools\CreateTrackingLink;
use App\Mcp\Tools\DeleteOffer;
use App\Mcp\Tools\DeletePost;
use App\Mcp\Tools\DisconnectAccount;
use App\Mcp\Tools\FetchOutlier;
use App\Mcp\Tools\GenerateOutlierBreakdown;
use App\Mcp\Tools\GetConnectUrl;
use App\Mcp\Tools\GetOffer;
use App\Mcp\Tools\GetOfferStats;
use App\Mcp\Tools\GetOutlier;
use App\Mcp\Tools\GetOutlierBreakdown;
use App\Mcp\Tools\GetPost;
use App\Mcp\Tools\GetStatsTimeseries;
use App\Mcp\Tools\ListBrands;
use App\Mcp\Tools\ListConnectedAccounts;
use App\Mcp\Tools\ListOffers;
use App\Mcp\Tools\ListOutliers;
use App\Mcp\Tools\ListPosts;
use App\Mcp\Tools\ListSavedOutliers;
use App\Mcp\Tools\RemoveSavedOutlier;
use App\Mcp\Tools\SaveOutlier;
use App\Mcp\Tools\SearchOutliers;
use App\Mcp\Tools\UpdateOffer;
use App\Mcp\Tools\UpdatePost;
use App\Mcp\Tools\UploadMedia;
use Laravel\Mcp\Server;

class ViewsMaxServer extends Server
{
    public string $serverName = 'ViewsMax';

    public string $serverVersion = '1.0.0';

    public string $instructions = <<<'TXT'
        ViewsMax lets you compose social posts and publish them to the user's
        connected accounts ({platforms}).
        Typical flow: list_connected_accounts to see what is connected, then
        create_post with the target platforms — as a draft, immediately
        (status "posted"), or scheduled (status "scheduled" + scheduled_at).
        TikTok/Instagram/YouTube posts need a video or image: host it with
        upload_media first and pass the returned media entry to create_post.
        {privacy_rules}
        Plans and billing are managed in the ViewsMax web app ({app_url}); this
        connector can't view or change them.
        Brands are named groups of connected accounts: list_brands shows them,
        and create_post accepts brand_id to post to a whole brand at once.
        Publishing is asynchronous; poll get_post to see per-platform results.
        Outliers are videos that massively over-performed their channel's
        average — use them for research and ideation: list_outliers to browse
        (search_outliers to search YouTube for a new topic), fetch_outlier to pull in a
        specific URL, generate_outlier_breakdown + get_outlier_breakdown for an
        AI analysis of why a video worked, and save_outlier to bookmark it in
        the user's library.
        TXT;

    // Show every tool on the first tools/list page.
    public int $defaultPaginationLength = 50;

    public array $tools = [
        // Posting
        ListConnectedAccounts::class,
        ListBrands::class,
        UploadMedia::class,
        CreatePost::class,
        ListPosts::class,
        GetPost::class,
        UpdatePost::class,
        DeletePost::class,
        // Monetization / offers
        ListOffers::class,
        CreateOffer::class,
        GetOffer::class,
        UpdateOffer::class,
        DeleteOffer::class,
        CreateTrackingLink::class,
        // Analytics
        GetOfferStats::class,
        GetStatsTimeseries::class,
        // Connections
        DisconnectAccount::class,
        GetConnectUrl::class,
        // Feature requests
        CreateFeatureRequest::class,
        // Outliers (research)
        ListOutliers::class,
        SearchOutliers::class,
        GetOutlier::class,
        FetchOutlier::class,
        GetOutlierBreakdown::class,
        GenerateOutlierBreakdown::class,
        ListSavedOutliers::class,
        SaveOutlier::class,
        RemoveSavedOutlier::class,
    ];

    /**
     * Swap in a tools/call handler that turns unexpected crashes into a
     * friendly isError tool result instead of leaking the raw exception
     * message — see SafeCallTool for the directory rules behind it.
     */
    public function boot()
    {
        $this->addMethod('tools/call', SafeCallTool::class);

        // Name only the platforms that are set up, matching create_post.
        $this->instructions = str_replace(
            ['{platforms}', '{privacy_rules}', '{app_url}'],
            [implode(', ', CreatePost::availablePlatforms()), CreatePost::privacyRules(), config('mcp.frontend_url')],
            $this->instructions
        );
    }

    /**
     * laravel/mcp v0.1.1 rejects initialize requests carrying a protocol
     * version it doesn't know, but the MCP spec says to counter-offer a
     * supported version and let the client decide. Its initialize handler is
     * private, so rewrite unknown versions to our newest supported one before
     * the package sees them — clients keep backward support, so this stays
     * compatible with future spec revisions without maintaining a list.
     */
    public function handle(string $rawMessage)
    {
        $message = json_decode($rawMessage, true);

        if (is_array($message)
            && ($message['method'] ?? null) === 'initialize'
            && ! in_array($message['params']['protocolVersion'] ?? null, $this->supportedProtocolVersion, true)) {
            $message['params']['protocolVersion'] = $this->supportedProtocolVersion[0];
            $rawMessage = json_encode($message);
        }

        return parent::handle($rawMessage);
    }
}
