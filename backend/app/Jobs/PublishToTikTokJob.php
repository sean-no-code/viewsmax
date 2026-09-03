<?php

namespace App\Jobs;

use App\Exceptions\TikTokPublishException;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Services\TikTokPublishService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publish a single post's TikTok target via the Content Posting API.
 *
 * The job initializes a PULL_FROM_URL direct post, stores the returned
 * publish_id, then re-queues itself (release) to poll status until TikTok
 * reports PUBLISH_COMPLETE or FAILED.
 */
class PublishToTikTokJob implements ShouldQueue
{
    use Queueable;
    use ResolvesTargetAccount;

    public $timeout = 120;

    // Each status poll re-queues the job; allow enough attempts to cover TikTok
    // pulling + processing the video (poll interval below × tries ≈ window).
    public $tries = 40;

    private const POLL_SECONDS = 15;

    public function __construct(public int $postTargetId) {}

    public function handle(TikTokPublishService $tiktok): void
    {
        /** @var PostTarget|null $target */
        $target = PostTarget::with('post.user')->find($this->postTargetId);
        if (! $target || $target->platform !== 'tiktok') {
            return;
        }
        if (in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            return;
        }

        $post = $target->post;
        [$account, $accountError] = $this->resolveTargetAccount($target, 'tiktok');
        if (! $account) {
            $this->markFailed($target, $accountError ?? 'No TikTok account connected.');
            return;
        }

        $meta = $target->meta ?? [];

        try {
            // Step 1: hand the media to TikTok and remember the publish_id.
            // A video is pushed directly (FILE_UPLOAD) so no public URL is
            // required; a photo slideshow is pulled by TikTok from the images'
            // public URLs (PULL_FROM_URL). Video wins when both are present.
            if (empty($meta['publish_id'])) {
                $caption = $target->caption_override ?: $post->caption ?: '';
                $videoMedia = collect($post->media ?? [])
                    ->first(fn ($m) => ($m['type'] ?? null) === 'video' && ! empty($m['path']));

                if ($videoMedia) {
                    // Post media is uploaded to the media disk (R2 in prod), so fall
                    // back to it — NOT filesystems.default — when a stored media item
                    // predates the `disk` key. Otherwise we look on the wrong disk and
                    // report the file "could not be found".
                    $disk = $videoMedia['disk'] ?? config('filesystems.media_disk', config('filesystems.default'));
                    $path = $videoMedia['path'];
                    if (! Storage::disk($disk)->exists($path)) {
                        $this->markFailed($target, 'The uploaded video file could not be found.');
                        return;
                    }

                    if (config('services.tiktok.video_pull_from_url')) {
                        // The file is on our own storage, so TikTok must fetch it rather
                        // than us pushing chunks — Content Sharing Guidelines,
                        // Technical Consideration 2(d).
                        // Hand over a signed proxy URL on the verified domain, the
                        // same route photo posts already use.
                        $result = $tiktok->publishVideoFromUrl(
                            $account,
                            $caption,
                            \App\Support\MediaProxy::url($path, $disk),
                            $target->options ?? []
                        );
                    } else {
                        // Opted out: push the bytes instead, for environments TikTok
                        // cannot reach to pull from.
                        $size = Storage::disk($disk)->size($path);
                        $stream = Storage::disk($disk)->readStream($path);
                        try {
                            $result = $tiktok->publishVideoFromStream($account, $caption, $stream, $size, $target->options ?? []);
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                    }
                } else {
                    // No video — try a photo slideshow. TikTok pulls each image
                    // by URL and only accepts a domain verified in the developer
                    // portal, so hand it a signed proxy URL on our domain (streams
                    // from R2) rather than the raw R2 public URL. Legacy media with
                    // no stored path falls back to its recorded url.
                    $images = collect($post->media ?? [])
                        ->filter(fn ($m) => ($m['type'] ?? null) === 'image' && (! empty($m['path']) || ! empty($m['url'])))
                        ->map(fn ($m) => ! empty($m['path'])
                            ? \App\Support\MediaProxy::url($m['path'], $m['disk'] ?? null)
                            : $m['url'])
                        ->values()
                        ->all();
                    if (empty($images)) {
                        $this->markFailed($target, 'No uploaded video or images to publish.');
                        return;
                    }

                    $result = $tiktok->publishPhotosFromUrls($account, $caption, $images, $target->options ?? []);
                }

                $meta['publish_id'] = $result['publish_id'];
                $meta['mode'] = $result['mode']; // direct | inbox
                $meta['log'] = array_merge($meta['log'] ?? [], $result['log'] ?? []);
                $target->forceFill([
                    'status' => PostTarget::STATUS_PUBLISHING,
                    'meta' => $meta,
                ])->save();

                $this->release(self::POLL_SECONDS);
                return;
            }

            // Step 2: poll status until terminal.
            $status = $tiktok->fetchStatus($account, $meta['publish_id']);
            $meta['last_status'] = $status;
            // Keep an audit trail of polls, bounded so meta can't grow without limit.
            $meta['log'] = array_slice(array_merge($meta['log'] ?? [], [[
                'step' => 'status',
                'status' => 200,
                'response' => $status,
                'at' => now()->toIso8601String(),
            ]]), -50);
            $target->meta = $meta;

            $state = $status['status'] ?? 'PROCESSING';
            // Inbox uploads terminate at SEND_TO_USER_INBOX — the video is in the
            // creator's TikTok app awaiting their tap to finish publishing.
            $inbox = ($meta['mode'] ?? 'direct') === 'inbox';
            if ($state === 'PUBLISH_COMPLETE' || ($inbox && $state === 'SEND_TO_USER_INBOX')) {
                $postId = $status['publicaly_available_post_id'][0]
                    ?? ($status['publicly_available_post_id'][0] ?? null);
                $target->forceFill([
                    'status' => PostTarget::STATUS_PUBLISHED,
                    'platform_post_id' => $postId,
                    'published_at' => now(),
                    'error' => $inbox ? 'Sent to your TikTok inbox — open TikTok to finish posting.' : null,
                ])->save();
                return;
            }

            if ($state === 'FAILED') {
                $this->markFailed($target, 'TikTok reported FAILED: '.($status['fail_reason'] ?? 'unknown'));
                return;
            }

            // Still processing — save progress and poll again.
            $target->save();
            $this->release(self::POLL_SECONDS);
        } catch (\Throwable $e) {
            Log::error('TikTok publish job error', [
                'post_target_id' => $target->id,
                'error' => $e->getMessage(),
            ]);
            // Persist the raw TikTok exchange captured before the failure so it
            // can be reviewed in the admin monitor.
            if ($e instanceof TikTokPublishException) {
                $meta['log'] = array_merge($meta['log'] ?? [], $e->context['log'] ?? []);
                if (! empty($e->context['mode'])) {
                    $meta['mode'] = $e->context['mode'];
                }
                $target->meta = $meta;
            }
            $this->markFailed($target, $e->getMessage());
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
     * Called when retries are exhausted (e.g. TikTok never finished processing).
     */
    public function failed(?\Throwable $e): void
    {
        $target = PostTarget::find($this->postTargetId);
        if ($target && ! in_array($target->status, [PostTarget::STATUS_PUBLISHED, PostTarget::STATUS_FAILED], true)) {
            $target->forceFill([
                'status' => PostTarget::STATUS_FAILED,
                'error' => $e?->getMessage() ?: 'Timed out waiting for TikTok to finish processing.',
            ])->save();
        }
    }
}
