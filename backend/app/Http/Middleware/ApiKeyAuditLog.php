<?php

namespace App\Http\Middleware;

use App\Models\ApiKeyRequest;
use App\Support\LoggedResponse;
use Closure;
use Illuminate\Http\Request as LaravelRequest;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Audit-logs every REST call made with a vmx_ API key: the user, method,
 * path, input, HTTP status and response body — the REST twin of
 * McpAuditLog. Login-session (web app) requests are not logged.
 *
 * Logged from terminate(), which gets the response as the client received
 * it (after AttachCreditsToResponse adds user_credits/active_plan) and runs
 * once it has been sent. Sits before access.active in the route group, so a
 * call refused for billing is logged with its 402. Logging failures are
 * reported but never affect the request.
 */
class ApiKeyAuditLog
{
    /** Input keys never written to the log. */
    private const HIDDEN_INPUT = ['password', 'password_confirmation', 'token', 'access_token', 'refresh_token'];

    public function handle(LaravelRequest $request, Closure $next): SymfonyResponse
    {
        return $next($request);
    }

    public function terminate(LaravelRequest $request, SymfonyResponse $response): void
    {
        if (! $request->attributes->get(ApiAuth::API_KEY_ATTRIBUTE) || ! $request->user()) {
            return;
        }

        try {
            ApiKeyRequest::create([
                'user_id' => $request->user()->id,
                'method' => $request->method(),
                'path' => '/'.$request->path(),
                'status' => $response->getStatusCode(),
                'request' => $request->except(self::HIDDEN_INPUT) ?: null,
                'response' => LoggedResponse::body($response),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
