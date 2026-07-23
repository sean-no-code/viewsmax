<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * @group Post Media
 *
 * Upload media (images / video) used by composed posts. Files are stored on the
 * configured default disk (S3 in production) and returned as a public URL that
 * the platform publishing APIs (e.g. TikTok PULL_FROM_URL) can fetch.
 */
class PostMediaController extends Controller
{
    /**
     * Upload a single post media file.
     *
     * @bodyparam file file Image or video file. Required. No-example
     * @bodyparam type string image|video. Required. No-example
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:image,video',
            // Video up to ~512MB; image up to 10MB. mimes kept broad but constrained.
            'file' => [
                'required',
                'file',
                // Each element of an array-style ruleset is a single rule; pipes are
                // NOT split here (unlike string rulesets), so keep rules separate.
                ...($request->input('type') === 'video'
                    ? ['mimetypes:video/mp4,video/quicktime', 'max:524288']
                    : ['image', 'max:10240']),
            ],
        ]);

        $user = Auth::user();
        // Post media is fetched by URL by the publishing APIs, so it must live on
        // a publicly reachable disk (R2 in production). See filesystems.media_disk.
        $disk = config('filesystems.media_disk') ?: config('filesystems.default');

        try {
            $path = $request->file('file')->store("posts/{$user->id}", $disk);
            $url = Storage::disk($disk)->url($path);
            // local/public disks return a root-relative path; make it absolute so
            // it passes the post's `url` validation and is fetchable off-box.
            if (! preg_match('#^https?://#i', $url)) {
                $url = url($url);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'type' => $data['type'],
                    'url' => $url,
                    'path' => $path,
                    'disk' => $disk,
                    'bytes' => $request->file('file')->getSize(),
                    'mime' => $request->file('file')->getMimeType(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Post media upload failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to upload media: '.$e->getMessage(),
            ], 500);
        }
    }
}
