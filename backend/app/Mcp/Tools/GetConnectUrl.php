<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Get the connect-account page URL')]
#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class GetConnectUrl extends ViewsMaxTool
{
    public function name(): string
    {
        return 'get_connect_url';
    }

    public function description(): string
    {
        return 'Get the URL of the ViewsMax Connections page where the user can '
            . 'connect a social platform. The user must open the page in a browser '
            . 'and click Connect there — the OAuth approval happens on that page '
            . 'and cannot be completed inside this conversation.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('platform')->description('Platform to connect (e.g. youtube, instagram, linkedin, x).')->required();
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['platform' => 'required|string|max:40']);
        $platform = strtolower(trim($validated['platform']));

        // The FE completes OAuth via its own popup + callback flow, so the
        // right destination is its Connections page — not a raw provider URL,
        // whose redirect the SPA could not finish from a cold browser tab.
        $known = array_keys((array) config('social.platforms'));
        if (! in_array($platform, $known, true)) {
            return ToolResult::error(
                "Unknown platform '{$platform}'. Platforms you can connect: "
                . implode(', ', CreatePost::availablePlatforms()) . '.'
            );
        }

        // Offer only platforms the user can post to once connected: without
        // app credentials the Connections page shows "Coming soon", and some
        // backend-only platforms (Google Business) have no publisher at all.
        $available = CreatePost::availablePlatforms();
        if (! in_array($platform, $available, true)) {
            return ToolResult::error(CreatePost::notSetUpError($platform, $available));
        }

        $frontend = rtrim((string) config('mcp.frontend_url'), '/');

        return ToolResult::json([
            'platform' => $platform,
            'connect_page_url' => $frontend . '/dashboard/connections',
            'instructions' => 'Open this page in a browser, sign in if needed, and click '
                . 'Connect next to ' . $platform . '. Approval happens in a popup there; '
                . 'afterwards list_connected_accounts will show the new account.',
        ]);
    }
}
