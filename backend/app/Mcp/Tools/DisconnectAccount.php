<?php

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class DisconnectAccount extends ViewsMaxTool
{
    public function name(): string
    {
        return 'disconnect_account';
    }

    public function description(): string
    {
        return 'Disconnect a social account by platform (e.g. x, youtube, tiktok). '
            . 'Fully disconnects it — nothing is left half-connected — and any '
            . 'pending posts targeting that platform will fail to publish.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema->string('platform')->description('Platform to disconnect.');
    }

    public function handle(array $arguments): ToolResult
    {
        $validated = Validator::validate($arguments, ['platform' => 'required|string|max:40']);
        $platform = strtolower(trim($validated['platform']));

        $user = $this->user();

        // Accounts live in two stores (newer SocialAccount + legacy Connection);
        // remove from both, mirroring what the Connections page offers. Beehiiv
        // is a separate connection type (API key, not OAuth) in its own model.
        $removed = $user->socialAccounts()->where('platform', $platform)->delete()
            + $user->connections()->where('provider', $platform)->delete();

        if ($platform === 'beehiiv') {
            $removed += $user->beehiivConnection()->delete();
        }

        if ($removed === 0) {
            return ToolResult::error("No connected {$platform} account found.");
        }

        return ToolResult::json(['disconnected' => true, 'platform' => $platform]);
    }
}
