<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Per-platform media rules enforced when a post is scheduled or published
 * (PostController::validatePayload), so the user learns about missing media,
 * too many images or an unsupported video before the post is saved, instead
 * of hours later inside the publish job.
 *
 * TikTok and YouTube keep their bespoke checks in the controller (they also
 * look at the media's storage path / privacy options); the TikTok photo size
 * check below is called from there.
 */
class MediaRules
{
    /** TikTok refuses a photo whose shorter side is over this (picture_size_check_failed). */
    public const TIKTOK_PHOTO_MAX_SHORT_SIDE = 1080;

    /**
     * platform => rule. Keys:
     *  - min:        minimum number of media items
     *  - max:        maximum number of media items (any type)
     *  - max_images: maximum number of images
     *  - max_videos: maximum number of videos (0 = video not supported here)
     *  - mixed:      whether images and a video may share one post
     */
    public const RULES = [
        'instagram' => ['min' => 1, 'max' => 10],
        // X video is not supported by ViewsMax yet, so a video is rejected
        // outright with the same wording PublishToXJob uses.
        'x' => ['max_images' => 4, 'max_videos' => 0, 'mixed' => false],
        // ViewsMax has no LinkedIn video upload either.
        'linkedin' => ['max_videos' => 0],
        // Bluesky video (app.bsky.video) is not wired up yet either.
        'bluesky' => ['max_images' => 4, 'max_videos' => 0, 'mixed' => false],
    ];

    /**
     * The first rule the media breaks for the platform, as a user-facing
     * message naming the platform, or null when the media is fine.
     *
     * @param  array<int, array{type?: string, url?: string, path?: string}>  $media
     */
    public static function error(string $platform, array $media): ?string
    {
        $rule = self::RULES[$platform] ?? null;
        if ($rule === null) {
            return null;
        }

        $label = config("social.platforms.{$platform}.label", ucfirst($platform));

        // Only entries that can actually be fetched count — a type with no
        // url or path has nothing to publish.
        $usable = array_values(array_filter($media, fn ($m) => ! empty($m['url']) || ! empty($m['path'])));
        $images = count(array_filter($usable, fn ($m) => ($m['type'] ?? null) === 'image'));
        $videos = count(array_filter($usable, fn ($m) => ($m['type'] ?? null) === 'video'));
        $total = count($usable);

        if (isset($rule['min']) && $total < $rule['min']) {
            return "{$label} requires at least {$rule['min']} image or video before publishing or scheduling.";
        }
        if (isset($rule['max']) && $total > $rule['max']) {
            return "{$label} allows at most {$rule['max']} media items per post.";
        }
        if (isset($rule['max_videos']) && $rule['max_videos'] === 0 && $videos > 0) {
            return "{$label} can't post video from here yet — use images for {$label}, or remove it from this post.";
        }
        if (isset($rule['max_videos']) && $videos > $rule['max_videos']) {
            return "{$label} allows only {$rule['max_videos']} video per post.";
        }
        if (isset($rule['max_images']) && $images > $rule['max_images']) {
            return "{$label} allows at most {$rule['max_images']} images per post.";
        }
        if (($rule['mixed'] ?? true) === false && $images > 0 && $videos > 0) {
            return "{$label} can't combine images and video in one post.";
        }

        return null;
    }

    /**
     * TikTok fails a whole photo slideshow when one image is larger than
     * 1080px on its shorter side, and only says `picture_size_check_failed`
     * without naming the image. Measure each stored image of a photo post
     * (no video) and name the first one that is too large, or return null.
     *
     * Best effort: an image with no storage path (an external URL) is not
     * fetched, and one whose dimensions cannot be read is let through and
     * logged. It only refuses when the image is known to be too large.
     *
     * @param  array<int, array{type?: string, url?: string, path?: string, disk?: string}>  $media
     */
    public static function tiktokPhotoError(array $media): ?string
    {
        $media = array_values(array_filter($media, fn ($m) => ! empty($m['url']) || ! empty($m['path'])));
        if (collect($media)->contains(fn ($m) => ($m['type'] ?? null) === 'video')) {
            return null; // a video post: the images are not sent as photos
        }

        $images = array_values(array_filter($media, fn ($m) => ($m['type'] ?? null) === 'image'));
        foreach ($images as $i => $image) {
            if (empty($image['path'])) {
                continue;
            }

            // Same fallback as the publish jobs: post media lives on the media disk.
            $disk = $image['disk'] ?? config('filesystems.media_disk', config('filesystems.default'));
            $size = self::storedImageSize($disk, $image['path']);
            if ($size === null) {
                continue;
            }

            [$width, $height] = $size;
            if (min($width, $height) > self::TIKTOK_PHOTO_MAX_SHORT_SIDE) {
                $position = count($images) > 1 ? 'Image '.($i + 1).' of '.count($images) : 'The image';

                return "{$position} is {$width}x{$height} pixels. TikTok allows at most "
                    .self::TIKTOK_PHOTO_MAX_SHORT_SIDE.'px on the shorter side of a photo. Resize it and upload it again.';
            }
        }

        return null;
    }

    /**
     * [width, height] of an image on a storage disk, or null (logged as a
     * warning, since the caller lets the post through) when it cannot be read.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function storedImageSize(string $disk, string $path): ?array
    {
        $error = null;
        try {
            $size = @getimagesizefromstring((string) Storage::disk($disk)->get($path));
        } catch (Throwable $e) {
            $size = false;
            $error = $e->getMessage();
        }

        if (! $size || empty($size[0]) || empty($size[1])) {
            Log::warning('TikTok photo size check: could not read image dimensions; allowing the post', [
                'disk' => $disk,
                'path' => $path,
                'error' => $error ?? 'not a readable image',
            ]);

            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }
}
