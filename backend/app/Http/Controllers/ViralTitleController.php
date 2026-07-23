<?php

namespace App\Http\Controllers;

use App\Models\ViralTitle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\UniqueConstraintViolationException;

class ViralTitleController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $perPage = $request->input('per_page', 10);
            $perPage = min($perPage, 100); // Limit to 100 per page
            
            $viralTitles = ViralTitle::select('id', 'title', 'created_at', 'updated_at')
                ->orderBy('created_at', 'desc')
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => $viralTitles->items(),
                'pagination' => [
                    'current_page' => $viralTitles->currentPage(),
                    'last_page' => $viralTitles->lastPage(),
                    'per_page' => $viralTitles->perPage(),
                    'total' => $viralTitles->total(),
                    'from' => $viralTitles->firstItem(),
                    'to' => $viralTitles->lastItem()
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('List viral titles failed: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve viral titles',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:1000'
            ]);

            $viralTitle = ViralTitle::create([
                'title' => $request->title
            ]);

            return response()->json([
                'success' => true,
                'data' => $viralTitle,
                'message' => 'Viral title created successfully'
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (UniqueConstraintViolationException $e) {
            // Find the existing viral title to return its details
            $existingViralTitle = ViralTitle::where('title', $request->title)->first();
            
            return response()->json([
                'success' => false,
                'message' => 'A viral title with this text already exists',
                'existing_viral_title' => $existingViralTitle ? [
                    'id' => $existingViralTitle->id,
                    'title' => $existingViralTitle->title,
                    'created_at' => $existingViralTitle->created_at
                ] : null
            ], 409);
        } catch (\Exception $e) {
            Log::error('Error creating viral title: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to create viral title: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $viralTitle = ViralTitle::findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $viralTitle
            ]);

        } catch (\Exception $e) {
            Log::error('Error retrieving viral title: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Viral title not found'
            ], 404);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $request->validate([
                'title' => 'required|string|max:1000'
            ]);

            $viralTitle = ViralTitle::findOrFail($id);
            $viralTitle->update([
                'title' => $request->title
            ]);

            return response()->json([
                'success' => true,
                'data' => $viralTitle,
                'message' => 'Viral title updated successfully'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating viral title: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update viral title: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        try {
            $viralTitle = ViralTitle::findOrFail($id);
            $viralTitle->delete();

            return response()->json([
                'success' => true,
                'message' => 'Viral title deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Error deleting viral title: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete viral title: ' . $e->getMessage()
            ], 500);
        }
    }
}
