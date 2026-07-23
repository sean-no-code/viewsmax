<?php

namespace App\Http\Controllers;

use App\Models\GoalType;
use Illuminate\Http\JsonResponse;

/**
 * @group Offers
 */
class GoalTypeController extends Controller
{
    /**
     * List the predefined conversion goal/event types (seeded reference list).
     */
    public function index(): JsonResponse
    {
        $types = GoalType::orderBy('sort_order')->orderBy('id')->get(['value', 'label', 'sort_order']);

        return response()->json([
            'success' => true,
            'data' => $types,
        ]);
    }
}
