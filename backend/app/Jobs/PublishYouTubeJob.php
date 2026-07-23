<?php

namespace App\Jobs;

use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Services\YouTubePublishService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publish a single post's YouTube target by uploading its video via the
 * Data API resumable upload. The post's caption becomes the video
 * title/description; per-target options carry the privacy choice.
 */
class PublishYouTubeJob implements ShouldQueue
{
    use Queueable;
    use ResolvesTargetAccount;

    // Large uploads can run long; give the job room but keep retries low since
    // a partial upload shouldn't be blindly repeated.
    public $timeout = 700;

    public $tries = 3;

    private const ALLOWED_PRIVACY = ['public', 'unlisted', 'private'];

    public function __construct(public int $postTargetId) {}

    public function handle(YouTubePublishService $youtube): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'youtube') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'youtube');
        if (! $account) {
            $this->markFailed($target, $accountError ?? 'No YouTube account connected.');

            return;
        }

        $videoMedia = collect($post->media ?? [])
            ->first(fn ($m) => ($m['type'] ?? null) === 'video' && ! empty($m['path']));
        if (! $videoMedia) {
            $this->markFailed($target, 'YouTube needs an uploaded video.');

            return;
        }

        // Post media is uploaded to the media disk (R2 in prod), so fall back to
        // it — NOT filesystems.default — when a stored media item predates the
        // `disk` key. Otherwise we look on the wrong disk and report the file
        // "could not be found".
        $disk = $videoMedia['disk'] ?? config('filesystems.media_disk', config('filesystems.default'));
        $path = $videoMedia['path'];
        if (! Storage::disk($disk)->exists($path)) {
            $this->markFailed($target, 'The uploaded video file could not be found.');

            return;
        }

        $caption = $target->caption_override ?: $post->caption ?: '';
        $options = $target->options ?? [];
        $privacy = in_array($options['privacy_status'] ?? null, self::ALLOWED_PRIVACY, true)
            ? $options['privacy_status']
            : 'public';

        $title = trim($caption) !== '' ? mb_substr(trim($caption), 0, 95) : 'New video';
        $snippet = [
            'title' => $title,
            'description' => $caption,
            'categoryId' => '22', // People & Blogs
        ];

        try {
            $accessToken = $youtube->freshAccessToken($account);
            $size = Storage::disk($disk)->size($path);
            $stream = Storage::disk($disk)->readStream($path);

            try {
                $result = $youtube->uploadVideo($accessToken, $stream, $size, $snippet, $privacy);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Custom thumbnail is best-effort: the video is already live, so a
            // rejected thumbnail (e.g. channel not eligible) must not fail the target.
            $thumbnailSet = $this->applyThumbnail($youtube, $target, $accessToken, $result['video_id'], $options);

            $meta = $target->meta ?? [];
            $meta['video_id'] = $result['video_id'];
            $meta['video_url'] = $result['video_url'];
            $meta['privacy_status'] = $privacy;
            if ($thumbnailSet !== null) {
                $meta['thumbnail_set'] = $thumbnailSet;
            }

            $target->forceFill([
                'status' => PostTarget::STATUS_PUBLISHED,
                'platform_post_id' => $result['video_id'],
                'published_at' => now(),
                'meta' => $meta,
                'error' => null,
            ])->save();
        } catch (\Throwable $e) {
            Log::error('YouTube publish job error', [
                'post_target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($target, $e->getMessage());
        }
    }

    /**
     * Upload a custom thumbnail for the video from the per-target options, if one
     * was set. Returns true/false when a thumbnail was attempted, or null when no
     * thumbnail was configured. Never throws — a thumbnail failure is non-fatal.
     */
    private function applyThumbnail(YouTubePublishService $youtube, PostTarget $target, string $accessToken, ?string $videoId, array $options): ?bool
    {
        $thumb = $options['thumbnail'] ?? null;
        if (! $videoId || ! is_array($thumb) || empty($thumb['path'])) {
            return null;
        }

        $disk = $thumb['disk'] ?? config('filesystems.media_disk', config('filesystems.default'));
        $path = $thumb['path'];
        if (! Storage::disk($disk)->exists($path)) {
            Log::warning('YouTube thumbnail file missing', ['post_target_id' => $target->id, 'path' => $path]);

            return false;
        }

        $stream = Storage::disk($disk)->readStream($path);
        try {
            $youtube->setThumbnail($accessToken, $videoId, $stream, $thumb['mime'] ?? 'image/jpeg');

            return true;
        } catch (\Throwable $e) {
            Log::warning('YouTube thumbnail set failed (non-fatal)', [
                'post_target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function markFailed(PostTarget $target, string $message): void
    {
        $target->forceFill([
            'status' => PostTarget::STATUS_FAILED,
            'error' => $message,
        ])->save();
    }

    /**
     * Called when retries are exhausted.
     */
    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $this->markFailed($target, $e?->getMessage() ?: 'YouTube upload failed.');
        }
    }
}
