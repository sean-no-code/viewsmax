<?php

namespace App\Http\Controllers;

use App\Models\Content;
use App\Models\Offer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Content
 *
 * Post and manage content (long-form body text + optional media file) that can be
 * attached to an Offer. Media is stored privately and streamed back via the media
 * endpoint. All endpoints are scoped to the authenticated user.
 */
class ContentController extends Controller
{
    /**
     * Maximum upload size for content media, in kilobytes (50 MB).
     */
    private const MAX_MEDIA_KB = 51200;

    private const MEDIA_DIR = 'content-media';

    /**
     * List content
     *
     * Returns the authenticated user's content, newest first. Optionally filter by offer or status.
     *
     * @queryParam offer_id integer Filter to content attached to this offer. Example: 12
     * @queryParam status string Filter by status (draft|published). Example: published
     *
     * @response 200 scenario="Success" {"data":[{"id":1,"user_id":1,"offer_id":12,"title":"Launch post","body":"...","media_filename":"promo.mp4","media_mime":"video/mp4","media_size":8388608,"status":"published","created_at":"2026-06-16T12:00:00.000000Z","updated_at":"2026-06-16T12:00:00.000000Z"}]}
     */
    public function index(Request $request)
    {
        $query = Auth::user()->contents()->latest();

        if ($request->filled('offer_id')) {
            $query->where('offer_id', $request->integer('offer_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        return ['data' => $query->get()];
    }

    /**
     * Create content
     *
     * Create a content post. Send as `multipart/form-data` when including a media file.
     * The `body` accepts long-form text. `media` accepts a single file up to 50 MB.
     *
     * @bodyParam title string required The content title. Example: 5 ways to grow your channel
     * @bodyParam body string The long-form body text (no length limit). Example: Once upon a time...
     * @bodyParam offer_id integer The id of an offer (owned by you) to attach this content to. Example: 12
     * @bodyParam status string The status: draft or published. Defaults to draft. Example: published
     * @bodyParam media file A media file to attach (image/video/document, max 50 MB).
     *
     * @response 201 scenario="Created" {"data":{"id":1,"user_id":1,"offer_id":12,"title":"Launch post","body":"...","media_filename":"promo.mp4","media_mime":"video/mp4","media_size":8388608,"status":"published"}}
     * @response 422 scenario="Validation error" {"message":"The title field is required.","errors":{"title":["The title field is required."]}}
     */
    public function store(Request $request)
    {
        $data = $this->validateContent($request, creating: true);

        $payload = [
            'user_id' => Auth::id(),
            'offer_id' => $data['offer_id'] ?? null,
            'title' => $data['title'],
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? Content::STATUS_DRAFT,
        ];

        $payload = array_merge($payload, $this->storeMedia($request));

        $content = Content::create($payload);

        return response()->json(['data' => $content], 201);
    }

    /**
     * Show content
     *
     * @urlParam id integer required The content id. Example: 1
     *
     * @response 200 scenario="Success" {"data":{"id":1,"title":"Launch post","body":"...","status":"published"}}
     * @response 404 scenario="Not found" {"message":"No query results for model [App\\Models\\Content] 1"}
     */
    public function show(string $id)
    {
        $content = Auth::user()->contents()->findOrFail($id);

        return ['data' => $content];
    }

    /**
     * Update content
     *
     * Update fields and/or replace the media file. Send `multipart/form-data` to replace media.
     *
     * @urlParam id integer required The content id. Example: 1
     * @bodyParam title string The content title. Example: Updated title
     * @bodyParam body string The long-form body text. Example: New body text...
     * @bodyParam offer_id integer The id of an offer (owned by you) to attach. Example: 12
     * @bodyParam status string draft or published. Example: published
     * @bodyParam media file Replacement media file (max 50 MB).
     *
     * @response 200 scenario="Updated" {"data":{"id":1,"title":"Updated title","status":"published"}}
     */
    public function update(Request $request, string $id)
    {
        $content = Auth::user()->contents()->findOrFail($id);

        $data = $this->validateContent($request, creating: false);

        $content->fill(array_filter([
            'offer_id' => $data['offer_id'] ?? null,
            'title' => $data['title'] ?? null,
            'body' => $data['body'] ?? null,
            'status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));

        // Allow explicitly detaching the offer by sending offer_id="".
        if ($request->exists('offer_id') && $request->input('offer_id') === '') {
            $content->offer_id = null;
        }

        if ($request->hasFile('media')) {
            $this->deleteMedia($content);
            $content->fill($this->storeMedia($request));
        }

        $content->save();

        return ['data' => $content];
    }

    /**
     * Delete content
     *
     * Deletes the content and any stored media file.
     *
     * @urlParam id integer required The content id. Example: 1
     *
     * @response 204 scenario="Deleted"
     */
    public function destroy(string $id)
    {
        $content = Auth::user()->contents()->findOrFail($id);
        $this->deleteMedia($content);
        $content->delete();

        return response()->noContent();
    }

    /**
     * Download content media
     *
     * Streams the stored media file for the content. Useful for large files.
     *
     * @urlParam id integer required The content id. Example: 1
     *
     * @response 404 scenario="No media" {"message":"This content has no media file."}
     */
    public function media(string $id): StreamedResponse
    {
        $content = Auth::user()->contents()->findOrFail($id);

        abort_unless($content->hasMedia() && Storage::disk('local')->exists($content->media_path), 404, 'This content has no media file.');

        return Storage::disk('local')->download(
            $content->media_path,
            $content->media_filename ?: 'media',
            ['Content-Type' => $content->media_mime ?: 'application/octet-stream']
        );
    }

    /**
     * Shared validation for store/update.
     */
    private function validateContent(Request $request, bool $creating): array
    {
        return $request->validate([
            'title' => ($creating ? 'required' : 'sometimes') . '|string|max:255',
            'body' => 'nullable|string',
            'status' => ['nullable', Rule::in(Content::STATUSES)],
            'offer_id' => [
                'nullable',
                'integer',
                // The offer must belong to the authenticated user.
                function ($attribute, $value, $fail) {
                    if ($value && ! Offer::where('id', $value)->where('user_id', Auth::id())->exists()) {
                        $fail('The selected offer is invalid or does not belong to you.');
                    }
                },
            ],
            'media' => 'nullable|file|max:' . self::MAX_MEDIA_KB,
        ]);
    }

    /**
     * Persist an uploaded media file and return the columns to store.
     */
    private function storeMedia(Request $request): array
    {
        if (! $request->hasFile('media')) {
            return [];
        }

        $file = $request->file('media');
        $path = $file->store(self::MEDIA_DIR, 'local');

        return [
            'media_path' => $path,
            'media_filename' => $file->getClientOriginalName(),
            'media_mime' => $file->getClientMimeType(),
            'media_size' => $file->getSize(),
        ];
    }

    /**
     * Remove the stored media file (if any) for the content.
     */
    private function deleteMedia(Content $content): void
    {
        if ($content->hasMedia() && Storage::disk('local')->exists($content->media_path)) {
            Storage::disk('local')->delete($content->media_path);
        }
    }
}
