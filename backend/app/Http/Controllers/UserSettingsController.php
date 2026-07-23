<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Per-user preference toggles (notification settings etc.). Settings live as
 * columns on the users table; add new toggles here and in the FE Settings page.
 */
class UserSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return $this->payload();
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'notify_post_failures' => 'sometimes|boolean',
            'locale' => 'sometimes|nullable|string|in:en,es,de,fr,pt',
        ]);

        $user = Auth::user();
        $user->fill($data)->save();

        return $this->payload('Settings updated.');
    }

    private function payload(string $message = 'OK'): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => [
                'notify_post_failures' => (bool) $user->notify_post_failures,
                'locale' => $user->locale,
            ],
        ]);
    }
}
