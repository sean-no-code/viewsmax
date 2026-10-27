<?php

namespace App\Http\Controllers;

use App\Services\Social\YouTubeDisconnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * "Delete my data" (Settings → Account & Data): removes everything ViewsMax
 * holds that came from Google — connected YouTube channels with their
 * imported videos and analytics, the YouTube publishing accounts and legacy
 * connections, and every stored Google token — and revokes ViewsMax's access
 * in the user's Google account. The ViewsMax account itself, posts, offers and
 * other platforms are untouched.
 */
class UserDataController extends Controller
{
    public function destroy(): JsonResponse
    {
        $user = Auth::user();

        $result = YouTubeDisconnector::disconnect($user);

        Log::info('User data deleted on request', ['user_id' => $user->id] + $result);

        return response()->json([
            'success' => true,
            'message' => 'Your YouTube data and Google access have been removed.',
            'data' => $result,
        ]);
    }
}
