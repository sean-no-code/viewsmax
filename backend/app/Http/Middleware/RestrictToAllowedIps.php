<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ops-only routes: allow the request only when the client IP is in the
 * comma-separated list (IPs or CIDR ranges) held in the given config key.
 * Everyone else gets a plain 404 so the route does not advertise itself.
 *
 *   ->middleware('allowed-ips')                           // monitoring.monitor_allowed_ips
 *   ->middleware('allowed-ips:some.other.config_key')
 */
class RestrictToAllowedIps
{
    public function handle(Request $request, Closure $next, string $configKey = 'monitoring.monitor_allowed_ips'): Response
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', (string) config($configKey)))));

        if ($allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed)) {
            abort(404);
        }

        return $next($request);
    }
}
