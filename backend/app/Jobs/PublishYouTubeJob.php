<?php

namespace App\Jobs;

use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\RecordsPublishStep;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Services\Social\CaptionRules;
use App\Services\YouTubePublishService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
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
    use RecordsPublishStep;
    use ResolvesTargetAccount;

    // Large uploads can run long; give the job room. A retry never repeats
    // sent bytes: the resumable session URI is kept on the target and probed
    // first, so a transient failure (or a kill on $timeout) resumes from the
    // offset Google committed.
    public $timeout = 700;

    public $tries = 3;

    public $backoff = 30;

    private const ALLOWED_PRIVACY = ['public', 'unlisted', 'private'];

    /** What each recorded step (see RecordsPublishStep) was doing, for the timeout message. */
    private const STEP_LABELS = [
        'token' => 'refreshing the YouTube access token',
        'session-init' => 'opening the YouTube upload session',
        'probe' => 'checking the YouTube upload session',
        'upload' => 'uploading the video to YouTube',
        'thumbnail' => 'setting the YouTube thumbnail',
    ];

    public function __construct(public int $postTargetId) {}

    /**
     * Cap how many YouTube publishes start per minute (config/publishing.php,
     * limiter registered in AppServiceProvider) so a YouTube spike cannot
     * starve the other platforms' jobs. Over the cap the job is released until
     * the window resets.
     */
    public function middleware(): array
    {
        return [new RateLimited('publish-youtube')];
    }

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

        // A grant made without the upload scope (the Settings/Analytics connect
        // asks for read + analytics only) can never upload: say so now instead
        // of streaming the whole video to Google for a 403.
        if ($account->scopes && ! in_array(YouTubePublishService::UPLOAD_SCOPE, (array) $account->scopes, true)) {
            $this->markFailed($target, YouTubePublishService::RECONNECT_FOR_UPLOAD);

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
        // Private is the safe default when the composer didn't choose (the
        // MCP tool requires an explicit value); the legacy path agrees.
        $privacy = in_array($options['privacy_status'] ?? null, self::ALLOWED_PRIVACY, true)
            ? $options['privacy_status']
            : 'private';

        // Posts saved before the publish-time title check could still be empty.
        if ($titleError = CaptionRules::titleError('youtube', $caption)) {
            $this->markFailed($target, $titleError);

            return;
        }

        $title = mb_substr(trim($caption), 0, 95);
        $snippet = [
            'title' => $title,
            'description' => $caption,
            'categoryId' => '22', // People & Blogs
        ];

        try {
            // Each outbound call is preceded by a persisted step marker so a
            // job killed on $timeout can still say where it stalled (failed()).
            $this->recordStep($target, 'token');
            $accessToken = $youtube->freshAccessToken($account);
            $size = Storage::disk($disk)->size($path);
            $stream = Storage::disk($disk)->readStream($path);

            try {
                $result = $youtube->uploadVideo(
                    $accessToken, $stream, $size, $snippet, $privacy,
                    fn (string $step, ?string $uploadUrl = null) => $this->recordUploadStep($target, $step, $uploadUrl),
                    $target->meta['upload_url'] ?? null
                );
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            // Custom thumbnail is best-effort: the video is already live, so a
            // rejected thumbnail (e.g. channel not eligible) must not fail the target.
            $thumbnailSet = $this->applyThumbnail($youtube, $target, $accessToken, $result['video_id'], $options);

            $meta = $target->meta ?? [];
            unset($meta['upload_url']);
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
        } catch (TransientPublishException $e) {
            // The session URI is already on the target — rethrow so the queue
            // retries and the next attempt resumes from the committed offset.
            Log::warning('YouTube publish job will retry', ['post_target_id' => $target->id, 'error' => $e->getMessage()]);

            throw $e;
        } catch (\Throwable $e) {
            Log::error('YouTube publish job error', [
                'post_target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
            YouTubePublishService::flagReauthIfExpired($account, $e);
            $this->markFailed($target, $e->getMessage());
        }
    }

    /**
     * Step marker plus, for the upload step, the resumable session URI: it
     * must be on the target before the first byte goes out, or a retry could
     * never find the session and would upload the video again.
     */
    private function recordUploadStep(PostTarget $target, string $step, ?string $uploadUrl): void
    {
        if ($uploadUrl !== null) {
            $target->meta = array_replace($target->meta ?? [], ['upload_url' => $uploadUrl]);
        }
        $this->recordStep($target, $step);
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

        $this->recordStep($target, 'thumbnail');
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

    /**
     * A definitive failure also forgets the upload session: a manual Retry
     * after the user fixes the post (new file, new title) must start afresh,
     * not resume a session that carries the old metadata.
     */
    private function markFailed(PostTarget $target, string $message): void
    {
        $meta = $target->meta ?? [];
        unset($meta['upload_url']);

        $target->forceFill([
            'status' => PostTarget::STATUS_FAILED,
            'error' => $message,
            'meta' => $meta,
        ])->save();
    }

    /**
     * Called when retries are exhausted. A job killed on $timeout never reached
     * handle()'s catch block, so name the step it was in from the marker.
     */
    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            if ($step = $this->timedOutStep($e, $target)) {
                $this->markFailed($target, 'Timed out while '.(self::STEP_LABELS[$step] ?? $step).'.');

                return;
            }
            $this->markFailed($target, $e?->getMessage() ?: 'YouTube upload failed.');
        }
    }
}
