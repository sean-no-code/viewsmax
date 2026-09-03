<?php

namespace App\Http\Controllers;

use App\Services\PostMediaDirectUpload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * @group Post Media
 *
 * Direct-to-storage (presigned) uploads for post media. The browser asks for
 * an upload session, PUTs the file straight to R2 using the signed URL(s),
 * then calls complete to verify the object and get the final media entry.
 * When the media disk is not S3-compatible the session returns strategy
 * "relay" and the client falls back to POST /posts/media.
 */
class PostMediaDirectUploadController extends Controller
{
    /** Mime => [media type, file extension]. Kept in sync with UploadMedia (MCP). */
    private const SUPPORTED_MIMES = [
        'image/jpeg' => ['image', 'jpg'],
        'image/png' => ['image', 'png'],
        'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'],
        'video/mp4' => ['video', 'mp4'],
        'video/quicktime' => ['video', 'mov'],
    ];

    /** Same caps as the relay endpoint (PostMediaController). */
    private const MAX_BYTES = [
        'image' => 10 * 1024 * 1024,
        'video' => 512 * 1024 * 1024,
    ];

    public function __construct(private readonly PostMediaDirectUpload $uploader)
    {
    }

    /**
     * Create a direct-upload session.
     *
     * @bodyparam type string image|video. Required.
     * @bodyparam filename string Original file name (display only). Required.
     * @bodyparam mime string File content type, e.g. video/mp4. Required.
     * @bodyparam size integer File size in bytes. Required.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:image,video',
            'filename' => 'required|string|max:255',
            'mime' => ['required', 'string', Rule::in(array_keys(self::SUPPORTED_MIMES))],
            'size' => 'required|integer|min:1',
        ]);

        if (self::SUPPORTED_MIMES[$data['mime']][0] !== $data['type']) {
            throw ValidationException::withMessages([
                'mime' => "Content type {$data['mime']} does not match declared type {$data['type']}.",
            ]);
        }

        if ($data['size'] > self::MAX_BYTES[$data['type']]) {
            throw ValidationException::withMessages([
                'size' => sprintf('File exceeds the %dMB %s limit.', self::MAX_BYTES[$data['type']] / 1048576, $data['type']),
            ]);
        }

        if (! $this->uploader->available()) {
            return response()->json(['success' => true, 'data' => ['strategy' => 'relay']]);
        }

        $extension = self::SUPPORTED_MIMES[$data['mime']][1];
        $path = sprintf('posts/%d/%s.%s', $request->user()->id, Str::uuid(), $extension);

        try {
            if ($data['size'] <= PostMediaDirectUpload::MULTIPART_THRESHOLD) {
                $signed = $this->uploader->presignPut($path, $data['mime']);

                return response()->json(['success' => true, 'data' => [
                    'strategy' => 'put',
                    'path' => $path,
                    'url' => $signed['url'],
                    'headers' => $signed['headers'],
                    'public_url' => $this->uploader->publicUrl($path),
                ]]);
            }

            $uploadId = $this->uploader->createMultipart($path, $data['mime']);
            $partCount = (int) ceil($data['size'] / PostMediaDirectUpload::PART_SIZE);

            $parts = [];
            for ($number = 1; $number <= $partCount; $number++) {
                $parts[] = [
                    'part_number' => $number,
                    'url' => $this->uploader->presignPart($path, $uploadId, $number),
                ];
            }

            return response()->json(['success' => true, 'data' => [
                'strategy' => 'multipart',
                'path' => $path,
                'upload_id' => $uploadId,
                'part_size' => PostMediaDirectUpload::PART_SIZE,
                'parts' => $parts,
                'public_url' => $this->uploader->publicUrl($path),
            ]]);
        } catch (\Throwable $e) {
            Log::error('Direct upload session creation failed', [
                'user_id' => $request->user()->id,
                'error' => $e->getMessage(),
            ]);

            // Signing/bucket trouble should not break uploads — fall back.
            return response()->json(['success' => true, 'data' => ['strategy' => 'relay']]);
        }
    }

    /**
     * Complete a direct upload.
     *
     * Finalizes the multipart upload (when upload_id is present), verifies the
     * stored object against the declared type's size/mime limits (a presigned
     * PUT cannot enforce size), and returns the media entry for the composer.
     *
     * @bodyparam type string image|video. Required.
     * @bodyparam path string Storage path returned by the session. Required.
     * @bodyparam upload_id string Multipart upload id (multipart only). No-example
     * @bodyparam parts object[] Uploaded parts as {part_number, etag} (multipart only). No-example
     */
    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => 'required|string|in:image,video',
            'path' => 'required|string|max:512',
            'upload_id' => 'sometimes|required|string',
            'parts' => 'required_with:upload_id|array|min:1',
            'parts.*.part_number' => 'required|integer|min:1',
            'parts.*.etag' => 'required|string',
        ]);

        $this->authorizePath($request, $data['path']);

        try {
            if (! empty($data['upload_id'])) {
                $this->uploader->completeMultipart($data['path'], $data['upload_id'], $data['parts']);
            }

            $stat = $this->uploader->stat($data['path']);
        } catch (\Throwable $e) {
            Log::warning('Direct upload completion failed', [
                'user_id' => $request->user()->id,
                'path' => $data['path'],
                'error' => $e->getMessage(),
            ]);

            // Leave no half-open multipart upload behind (R2 bills stored parts).
            if (! empty($data['upload_id'])) {
                rescue(fn () => $this->uploader->abortMultipart($data['path'], $data['upload_id']), report: false);
            }

            return response()->json([
                'success' => false,
                'message' => 'Upload could not be finalized. Please try again.',
            ], 422);
        }

        $withinCap = $stat['bytes'] <= self::MAX_BYTES[$data['type']];
        $mimeMatches = str_starts_with($stat['mime'], $data['type'] . '/');

        if (! $withinCap || ! $mimeMatches) {
            $this->uploader->delete($data['path']);

            return response()->json([
                'success' => false,
                'message' => $withinCap
                    ? 'Uploaded file is not a valid ' . $data['type'] . '.'
                    : sprintf('File exceeds the %dMB %s limit.', self::MAX_BYTES[$data['type']] / 1048576, $data['type']),
            ], 422);
        }

        return response()->json(['success' => true, 'data' => [
            'type' => $data['type'],
            'url' => $this->uploader->publicUrl($data['path']),
            'path' => $data['path'],
            'disk' => $this->uploader->diskName(),
            'bytes' => $stat['bytes'],
            'mime' => $stat['mime'],
        ]]);
    }

    /**
     * Abort a multipart direct upload.
     *
     * @bodyparam path string Storage path returned by the session. Required.
     * @bodyparam upload_id string Multipart upload id. Required.
     */
    public function abort(Request $request): JsonResponse
    {
        $data = $request->validate([
            'path' => 'required|string|max:512',
            'upload_id' => 'required|string',
        ]);

        $this->authorizePath($request, $data['path']);

        rescue(fn () => $this->uploader->abortMultipart($data['path'], $data['upload_id']), report: false);

        return response()->json(['success' => true]);
    }

    /** Sessions only ever mint paths under the caller's own prefix. */
    private function authorizePath(Request $request, string $path): void
    {
        if (! str_starts_with($path, sprintf('posts/%d/', $request->user()->id))) {
            abort(403, 'Path does not belong to the authenticated user.');
        }
    }
}
