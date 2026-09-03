<?php

namespace App\Http\Controllers;

use App\Models\OutlierSavedFilter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OutlierSavedFilterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = OutlierSavedFilter::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $filters]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'filters' => 'required|array',
        ]);

        $filter = OutlierSavedFilter::create([
            'user_id' => $request->user()->id,
            'name' => $validated['name'],
            'filters' => $validated['filters'],
        ]);

        return response()->json(['success' => true, 'data' => $filter], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $filter = OutlierSavedFilter::where('user_id', $request->user()->id)->findOrFail($id);
        $filter->delete();

        return response()->json(['success' => true, 'message' => 'Filter deleted']);
    }
}
