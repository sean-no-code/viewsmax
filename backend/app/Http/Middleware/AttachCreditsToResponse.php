<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AttachCreditsToResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Pass the request to the application to generate the response first
        $response = $next($request);

        // 2. Check if the response is JSON and if the user is authenticated
        if ($response instanceof JsonResponse && $request->user()) {
            
            // 3. Get the existing data as an associative array
            $data = $response->getData(true);

            // Edge case: If getData returns null or a non-array, initialize it
            if (!is_array($data)) {
                $data = [];
            }

            // 4. Get the authenticated user
            $user = $request->user();
            
            // 5. Inject the wallet balance
            // Because of bavix/laravel-wallet lazy loading, this works 
            // even if the wallet doesn't exist yet (it will be created now).
            $data['user_credits'] = $user->balanceInt;
            
            // 6. Append user data (id and active_plan) to the top level of the response
            $data['user_id'] = $user->id;
            $data['active_plan'] = $user->activePlan();

            // 7. Set the modified data back into the response
            $response->setData($data);
        }

        return $response;
    }
}