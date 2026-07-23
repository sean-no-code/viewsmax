<?php

namespace App\Http\Controllers;

use App\Models\Plan;

/**
 * @group Plans
 */
class PlanController extends Controller
{
    /**
     * Display a listing of plans.
     */
    public function index()
    {
        try {
            $plans = Plan::active()->get();

            return response()->json([
                'success' => true,
                'data' => $plans
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch plans',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified plan.
     */
    public function show($id)
    {
        try {
            $plan = Plan::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $plan
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Plan not found',
                'error' => $e->getMessage()
            ], 404);
        }
    }

}
