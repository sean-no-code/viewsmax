<?php

namespace App\Jobs;

use App\Exceptions\TikTokPublishException;
use App\Exceptions\TikTokUploadInterruptedException;
use App\Exceptions\TransientPublishException;
use App\Jobs\Concerns\RecordsPublishStep;
use App\Jobs\Concerns\ResolvesTargetAccount;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\TikTokPublishService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Publish a single post's TikTok target via the Content Posting API.
 *
 * The job initializes a direct post, stores the returned publish_id the
 * moment TikTok hands it out, then re-queues itself (release) to poll status
 * until TikTok reports PUBLISH_COMPLETE or FAILED.
 *
 * The publish_id is the point of no return: once it is on the target no later
 * attempt inits again — a worker killed or timed out mid-upload polls that id
 * and lets TikTok's own verdict decide.
 */
class PublishToTikTokJob implements ShouldQueue
{
    use Queueable;
    use RecordsPublishStep;
    use ResolvesTargetAccount;

    // Just under the database queue's retry_after (330 s in config/queue.php):
    // past that a second worker would start the same job while the first is
    // still uploading, which is exactly the double-publish this job guards
    // against. It covers the init (≤ 100 s with retries) plus the chunked
    // FILE_UPLOAD path at its usual ~1 s per 8 MB chunk; a pathological
    // upload that outlives it is killed safely, because the publish_id is
    // already persisted and the next attempt only polls. HTTP timeouts (30 s
    // per call, see TikTokPublishService) stay well below it.
    public $timeout = 320;

    // Each status poll re-queues the job; allow enough attempts to cover TikTok
    // pulling + processing the video (poll interval below × tries ≈ window).
    public $tries = 40;

    private const POLL_SECONDS = 15;

    public function __construct(public int $postTargetId) {}

    /**
     * Cap how many TikTok job runs start per minute (config/publishing.php,
     * limiter registered in AppServiceProvider) so a TikTok spike cannot
     * starve the other platforms' jobs. Each status poll is a run too, so the
     * cap is set well above the YouTube one.
     */
    public function middleware(): array
    {
        return [new RateLimited('publish-tiktok')];
    }

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

        [$account, $accountError] = $this->resolveTargetAccount($target, 'tiktok');
        if (! $account) {
            $this->markFailed($target, $accountError ?? 'No TikTok account connected.');
            return;
        }

        $meta = $target->meta ?? [];

        try {
            // Step 1: hand the media to TikTok. Only when no publish_id is on
            // the target yet — a stored one means TikTok already accepted a
            // post for this target, so re-running init would duplicate it.
            if (empty($meta['publish_id'])) {
                $this->handOver($target, $account, $tiktok, $meta);
                $this->release(self::POLL_SECONDS);
                return;
            }

            // Step 2: poll status until terminal.
            $meta = $this->recordStep($target, 'status');
            $status = $tiktok->fetchStatus($account, $meta['publish_id']);
            $meta['last_status'] = $status;
            $this->appendLog($meta, 'status', 200, $status);
            $target->meta = $meta;

            $state = $status['status'] ?? 'PROCESSING';
            // Inbox uploads terminate at SEND_TO_USER_INBOX — the video is in the
            // creator's TikTok app awaiting their tap to finish publishing.
            $inbox = ($meta['mode'] ?? 'direct') === 'inbox';
            if ($state === 'PUBLISH_COMPLETE' || ($inbox && $state === 'SEND_TO_USER_INBOX')) {
                // The public id only shows up once moderation approves the post;
                // until then meta.url is the profile and posts:resolve-tiktok-ids
                // fills in the real id + link later.
                $postId = self::publicPostId($status);
                $meta['url'] = TikTokPublishService::postUrl($account, $meta['publish_id'], $postId);
                $target->forceFill([
                    'status' => PostTarget::STATUS_PUBLISHED,
                    'platform_post_id' => $postId,
                    'published_at' => now(),
                    'meta' => $meta,
                    'error' => $inbox ? 'Sent to your TikTok inbox — open TikTok to finish posting.' : null,
                ])->save();
                return;
            }

            if ($state === 'FAILED') {
                $this->markFailed($target, TikTokPublishService::rejection('TikTok rejected the post', $status['fail_reason'] ?? null));
                return;
            }

            // Still processing — save progress and poll again.
            $target->save();
            $this->release(self::POLL_SECONDS);
        } catch (TikTokUploadInterruptedException $e) {
            // TikTok has the init and maybe the bytes: not our verdict to make.
            // Note it and let the status polls deliver TikTok's.
            $this->keepPending($target, $meta, $e->context['log'] ?? [], 'upload interrupted: '.$e->getMessage());
        } catch (TransientPublishException $e) {
            // A status check TikTok could not answer; the post may already be
            // live, so stay pending and ask again (bounded by $tries).
            $this->keepPending($target, $meta, [], $e->getMessage());
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

    /**
     * Step 1: give TikTok the media and persist the publish_id it returns. A
     * video is pulled by TikTok from a signed URL on our domain, or pushed as
     * chunks (FILE_UPLOAD) where TikTok cannot reach us; a photo slideshow is
     * always pulled. Video wins when both are present.
     *
     * The FILE_UPLOAD path saves the publish_id BEFORE the chunk loop (see the
     * class docblock); the pull paths have nothing after init, so saving on
     * return is the same moment.
     */
    private function handOver(PostTarget $target, SocialAccount $account, TikTokPublishService $tiktok, array &$meta): void
    {
        $post = $target->post;
        // Step markers (meta.step) are written BEFORE each call, unlike
        // meta.log, so a job killed on $timeout can still be explained.
        $meta = $this->recordStep($target, 'init');
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
                throw new TikTokPublishException('The uploaded video file could not be found.');
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
                // cannot reach to pull from. The stored mime travels with the
                // chunks (TikTok rejects a .mov labelled video/mp4).
                $size = Storage::disk($disk)->size($path);
                $stream = Storage::disk($disk)->readStream($path);
                $seen = 0; // init events already merged by the callback
                try {
                    $result = $tiktok->publishVideoFromStream(
                        $account,
                        $caption,
                        $stream,
                        $size,
                        $target->options ?? [],
                        $videoMedia['mime'] ?? 'video/mp4',
                        function (array $init) use ($target, $account, &$meta, &$seen) {
                            $this->rememberPublishId($target, $account, $meta, $init, $init['log']);
                            $seen = count($init['log']);
                            $meta = $this->recordStep($target, 'upload');
                        }
                    );
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $this->rememberPublishId($target, $account, $meta, $result, array_slice($result['log'], $seen));

                return;
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
                throw new TikTokPublishException('No uploaded video or images to publish.');
            }

            $result = $tiktok->publishPhotosFromUrls($account, $caption, $images, $target->options ?? []);
        }

        $this->rememberPublishId($target, $account, $meta, $result, $result['log'] ?? []);
    }

    /**
     * Persist the publish_id (+ mode, upload_url, the new audit events) and
     * flip the target to publishing. The FILE_UPLOAD path calls it once right
     * after init and again after the chunks.
     */
    private function rememberPublishId(PostTarget $target, SocialAccount $account, array &$meta, array $result, array $events): void
    {
        $meta['publish_id'] = $result['publish_id'];
        $meta['mode'] = $result['mode']; // direct | inbox
        if (! empty($result['upload_url'])) {
            $meta['upload_url'] = $result['upload_url'];
        }
        $meta['log'] = array_merge($meta['log'] ?? [], $events);
        // Until the public id exists the profile is the best link we have.
        $meta['url'] = TikTokPublishService::postUrl($account, $meta['publish_id'], null);
        $target->forceFill([
            'status' => PostTarget::STATUS_PUBLISHING,
            'meta' => $meta,
        ])->save();
    }

    /**
     * Something other than a TikTok verdict interrupted us after TikTok had
     * accepted the post (or while asking for its status): keep the target
     * pending, note why in meta.log, and poll again.
     */
    private function keepPending(PostTarget $target, array $meta, array $events, string $note): void
    {
        Log::warning('TikTok publish job: staying pending', ['post_target_id' => $target->id, 'note' => $note]);
        $meta['log'] = array_merge($meta['log'] ?? [], $events);
        $this->appendLog($meta, 'pending', 0, $note);
        $target->forceFill(['status' => PostTarget::STATUS_PUBLISHING, 'meta' => $meta])->save();
        $this->release(self::POLL_SECONDS);
    }

    /** Append one audit event, keeping meta bounded so it can't grow without limit. */
    private function appendLog(array &$meta, string $step, int $status, $response): void
    {
        $meta['log'] = array_slice(array_merge($meta['log'] ?? [], [[
            'step' => $step,
            'status' => $status,
            'response' => $response,
            'at' => now()->toIso8601String(),
        ]]), -50);
    }

    /**
     * The public post id from a status payload, or null while moderation is
     * still pending. TikTok's docs spell the key "publicaly"; accept both.
     */
    public static function publicPostId(array $status): ?string
    {
        $id = $status['publicaly_available_post_id'][0]
            ?? ($status['publicly_available_post_id'][0] ?? null);

        return $id === null ? null : (string) $id;
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
                'error' => match ($this->timedOutStep($e, $target)) {
                    'init' => 'Timed out while handing the media to TikTok.',
                    'upload' => 'Timed out while uploading the video to TikTok.',
                    'status' => "Timed out while checking TikTok's processing status.",
                    default => $e?->getMessage() ?: 'Timed out waiting for TikTok to finish processing.',
                },
            ])->save();
        }
    }
}
