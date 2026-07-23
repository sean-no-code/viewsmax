<?php

namespace App\Http\Controllers\Oauth;

use Illuminate\Http\Request;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's approve controller, extended so the MCP consent screen can
 * downgrade a connection to read-only. Bound over the parent in the container
 * (see AppServiceProvider) so Passport's own approve route resolves to this.
 *
 * Claude and other MCP clients don't request scopes, so the token would carry
 * the full default (mcp:read + mcp:write). When the user picks "read-only" on
 * the consent screen (access=read), we strip mcp:write before completing the
 * grant, and the issued token can then only reach read tools.
 */
class McpApproveAuthorizationController extends ApproveAuthorizationController
{
    public function approve(Request $request, ResponseInterface $psrResponse): Response
    {
        $authRequest = $this->getAuthRequestFromSession($request);

        if ($request->input('access') === 'read') {
            $authRequest->setScopes(array_values(array_filter(
                $authRequest->getScopes(),
                fn ($scope) => $scope->getIdentifier() !== 'mcp:write',
            )));
        }

        $authRequest->setAuthorizationApproved(true);

        return $this->withErrorHandling(fn () => $this->convertResponse(
            $this->server->completeAuthorizationRequest($authRequest, $psrResponse)
        ), $authRequest->getGrantTypeId() === 'implicit');
    }
}
