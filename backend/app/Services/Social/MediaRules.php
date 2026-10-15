<?php

namespace App\Services\Social;

/**
 * Per-platform media rules enforced when a post is scheduled or published
 * (PostController::validatePayload), so the user learns about missing media,
 * too many images or an unsupported video before the post is saved, instead
 * of hours later inside the publish job.
 *
 * TikTok and YouTube keep their bespoke checks in the controller (they also
 * look at the media's storage path / privacy options).
 */
class MediaRules
{
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
        'bluesky' => ['max_images' => 4, 'max_videos' => 1, 'mixed' => false],
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
}
