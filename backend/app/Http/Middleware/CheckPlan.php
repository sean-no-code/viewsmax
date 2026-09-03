<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPlan
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$plans): Response
    {
        if (!$request->user()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Admin users bypass plan checks
        if ($request->user()->isAdmin()) {
            return $next($request);
        }

        $userPlan = $request->user()->activePlan();
        
        if (!$userPlan) {
            return response()->json([
                'success' => false,
                'message' => 'No active plan found'
            ], 403);
        }

        if (!in_array($userPlan->name, $plans)) {
            return response()->json([
                'success' => false,
                'message' => 'Plan upgrade required',
                'current_plan' => $userPlan->name,
                'required_plans' => $plans
            ], 403);
        }

        return $next($request);
    }
}
