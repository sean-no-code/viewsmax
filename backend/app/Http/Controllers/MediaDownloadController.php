<?php

namespace App\Http\Controllers;

use App\Support\MediaProxy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Streams a post-media object from R2 through OUR (TikTok/Instagram-verified)
 * domain, so their PULL_FROM_URL fetch succeeds where the raw R2 URL is refused.
 *
 * The route is guarded by the `signed` middleware: any unsigned, tampered, or
 * expired request is rejected before this runs, so the link can't be repurposed
 * to probe arbitrary storage. We additionally refuse any disk that isn't a media
 * disk and any path traversal.
 */
class MediaDownloadController extends Controller
{
    public function __invoke(Request $request, string $ref)
    {
        $decoded = MediaProxy::decode($ref);
        abort_unless($decoded, 404);
        [$disk, $path] = $decoded;

        $allowed = array_values(array_filter([
            config('filesystems.media_disk'),
            config('filesystems.default'),
        ]));
        abort_unless(in_array($disk, $allowed, true), 404);
        abort_if(str_contains($path, '..'), 404);
        abort_unless(Storage::disk($disk)->exists($path), 404);

        return Storage::disk($disk)->response($path);
    }
}
