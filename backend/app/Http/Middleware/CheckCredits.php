<?php

namespace App\Http\Middleware;

use App\Services\CreditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckCredits
{
    protected $creditService;

    public function __construct(CreditService $creditService)
    {
        $this->creditService = $creditService;
    }

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $type): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        $cost = $this->creditService->getCost($type);
        
        // Handle multiplication for bulk operations (like thumbnails)
        if ($type === CreditService::THUMBNAIL_GENERATION_OPERATION && $request->has('number_of_thumbnails')) {
            $count = (int) $request->input('number_of_thumbnails', 1);
            // Ensure at least 1
            $count = max(1, $count);
            $cost *= $count;
        }

        if (!$this->creditService->checkCredits($user, $cost)) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient credits. Please purchase more credits to continue.',
                'required' => $cost,
                'available' => $user->balanceInt
            ], 402); // 402 Payment Required
        }

        // Store the calculated cost in the request for the controller to use
        $request->attributes->set('credit_cost', $cost);
        $request->attributes->set('credit_description', "Generated " . ($type === CreditService::THUMBNAIL_GENERATION_OPERATION && isset($count) ? "$count thumbnails" : "a $type"));

        return $next($request);
    }
}

