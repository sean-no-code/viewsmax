<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Http\Controllers\Auth\WebRegisterController;
use App\Support\OAuthIntendedClient;
use Illuminate\Http\Request;

/**
 * The agent signup chain (register → verify → connect → card → consent)
 * keeps its own copy of the pending /oauth/authorize URL and client name,
 * because Laravel's shared `url.intended` can be overwritten by any guest
 * redirect mid flow. Every page in the chain calls this first.
 */
trait RemembersAgentSignup
{
    /**
     * Copy the pending authorize URL + client name from `url.intended` into the
     * flow's own session keys (first sighting wins) and return the client name.
     */
    private function rememberAgent(Request $request): ?string
    {
        $session = $request->session();
        $intended = $session->get('url.intended');

        if (! $session->has(WebRegisterController::SESSION_AUTHORIZE_URL) && OAuthIntendedClient::isAuthorizeUrl($intended)) {
            $session->put(WebRegisterController::SESSION_AUTHORIZE_URL, $intended);
            $session->put(WebRegisterController::SESSION_CLIENT, OAuthIntendedClient::nameFrom($intended));
        }

        return $session->get(WebRegisterController::SESSION_CLIENT);
    }
}
