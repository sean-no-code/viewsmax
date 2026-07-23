<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RestrictFreePlan
{
    /**
     * Handle an incoming request.
     * Blocks free plan users from using POST and PUT methods.
     * GET and DELETE requests are allowed for all users.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Allow GET and DELETE requests for all users
        if ($request->isMethod('GET') || $request->isMethod('DELETE')) {
            return $next($request);
        }

        // Check if user has a free plan or no active plan
        // Block POST and PUT requests for free plan users
        $activePlan = $request->user()->activePlan();
        
        // Log for debugging
        Log::info('RestrictFreePlan middleware check', [
            'user_id' => $request->user()->id,
            'method' => $request->method(),
            'has_active_plan' => $activePlan !== null,
            'plan_name' => $activePlan ? $activePlan->name : 'none',
        ]);
        
        // Block if no active plan or if plan is free
        if (!$activePlan || strtolower($activePlan->name) === 'free') {
            Log::info('Blocking free plan user from POST/PUT', [
                'user_id' => $request->user()->id,
                'method' => $request->method(),
                'plan_name' => $activePlan ? $activePlan->name : 'no active plan',
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Upgrade to pro to use this feature'
            ], 403);
        }

        return $next($request);
    }
}

