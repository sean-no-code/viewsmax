<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @group User Default Reference Image
 *
 * APIs for managing a user's default reference image for image generation
 */
class DefaultImageController extends Controller
{
    /**
     * Get Default Reference Image
     *
     * Retrieve the authenticated user's current default reference image.
     *
     * @response {"success":true,"message":"Default reference image retrieved successfully","data":{"has_default_image":true,"image_url":"https://your-domain.com/storage/user-defaults/1/image.png"}}
     *
     * @response {"success":true,"message":"No default reference image set","data":{"has_default_image":false,"image_url":null}}
     */
    public function show(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->hasDefaultReferenceImage()) {
            return response()->json([
                'success' => true,
                'message' => 'No default reference image set',
                'data' => [
                    'has_default_image' => false,
                    'image_url' => null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Default reference image retrieved successfully',
            'data' => [
                'has_default_image' => true,
                'image_url' => $user->default_reference_image_url,
            ],
        ]);
    }

    /**
     * Upload Default Reference Image
     *
     * Upload a new default reference image for the authenticated user. This image will be used as a fallback when no reference image is provided in generation requests.
     *
     * @bodyparam image file Image file (max 10MB). Required. No-example
     *
     * @response {"success":true,"message":"Default reference image uploaded successfully","data":{"has_default_image":true,"image_url":"https://your-domain.com/storage/user-defaults/1/image.png"}}
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'image', 'max:10240'],
        ]);

        $user = Auth::user();

        try {
            // Delete old image if exists
            if ($user->default_reference_image_path) {
                Storage::disk('public')->delete($user->default_reference_image_path);
            }

            // Store new image
            $path = $request->file('image')->store(
                "user-defaults/{$user->id}",
                'public'
            );

            // Update user
            $user->default_reference_image_path = $path;
            $user->save();

            Log::info('Default reference image uploaded', [
                'user_id' => $user->id,
                'path' => $path,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Default reference image uploaded successfully',
                'data' => [
                    'has_default_image' => true,
                    'image_url' => $user->default_reference_image_url,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to upload default reference image', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload image: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete Default Reference Image
     *
     * Remove the authenticated user's default reference image.
     *
     * @response {"success":true,"message":"Default reference image removed successfully"}
     *
     * @response 400 {"success":false,"message":"No default reference image to remove"}
     */
    public function destroy(): JsonResponse
    {
        $user = Auth::user();

        if (! $user->default_reference_image_path) {
            return response()->json([
                'success' => false,
                'message' => 'No default reference image to remove',
            ], 400);
        }

        try {
            // Delete file
            Storage::disk('public')->delete($user->default_reference_image_path);

            // Update user
            $user->default_reference_image_path = null;
            $user->save();

            Log::info('Default reference image removed', ['user_id' => $user->id]);

            return response()->json([
                'success' => true,
                'message' => 'Default reference image removed successfully',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to remove default reference image', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to remove image: '.$e->getMessage(),
            ], 500);
        }
    }
}
