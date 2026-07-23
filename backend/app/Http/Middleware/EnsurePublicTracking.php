<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePublicTracking
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Check if this request is for the tracker script OR any tracking API endpoint
        if ($request->is('tracker.js') || $request->is('api/track/*')) {
            // Dynamically override CORS config to allow ANY origin (*)
            config(['cors.allowed_origins' => ['*']]);
        }

        return $next($request);
    }
}
