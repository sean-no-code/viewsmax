<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostTarget;
use App\Services\Social\MediaRules;
use App\Services\Social\PostPublishDispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

/**
 * @group Posts
 *
 * Compose multi-platform posts (YouTube, TikTok, X, LinkedIn, Threads,
 * Instagram, Bluesky) as drafts, publish immediately, or schedule. One post
 * fans out to per-platform targets that each report their own publish
 * status — publishing is asynchronous, so poll GET /api/posts/{id}.
 */
class PostController extends Controller
{
    /**
     * List the user's posts (with targets). Optional filters: status, and a
     * scheduled_at date window (from/to) used by the calendar view.
     */
    public function index(Request $request)
    {
        $query = Auth::user()->posts()->with('targets.socialAccount', 'comments.targetComments')->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($request->input('from'))->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($request->input('to'))->endOfDay());
        }

        return ['data' => $query->get()];
    }

    public function show(string $id)
    {
        return Auth::user()->posts()->with('targets.socialAccount', 'comments.targetComments')->findOrFail($id);
    }

    /**
     * Create a post.
     *
     * Composes a post and fans it out to the given platforms. Save as a draft,
     * publish immediately (status=posted), or schedule (status=scheduled with
     * scheduled_at). Publishing is asynchronous — poll GET /api/posts/{id} for
     * per-platform results.
     *
     * @bodyParam caption string The post text / caption. Example: Big news today!
     * @bodyParam platforms string[] Target platforms: youtube, tiktok, instagram, x, linkedin, threads, facebook, bluesky, pinterest. Example: ["tiktok","instagram"]
     * @bodyParam media object[] Media entries, usually from POST /api/posts/media.
     * @bodyParam media[].type string image or video. Example: image
     * @bodyParam media[].url string Public URL of the media. Example: https://cdn.example/1.jpg
     * @bodyParam media[].path string Storage path (YouTube requires a video path). Example: posts/1/clip.mp4
     * @bodyParam status string draft (default), scheduled, or posted. Example: draft
     * @bodyParam scheduled_at string ISO-8601 datetime; required when status is scheduled. Example: 2026-07-20T18:30:00Z
     * @bodyParam brand_id integer The brand selected in the composer (informational). Example: 1
     * @bodyParam overrides object Per-platform caption overrides, keyed by platform.
     * @bodyParam options object Per-platform publish options, keyed by platform.
     * @bodyParam options.tiktok.privacy_level string TikTok privacy, e.g. SELF_ONLY or PUBLIC_TO_EVERYONE. Example: SELF_ONLY
     * @bodyParam options.tiktok.auto_add_music boolean Auto-add TikTok's recommended music to a photo slideshow. Slideshows only; ignored for video. Defaults to false. Example: false
     * @bodyParam options.youtube.privacy_status string public, unlisted, or private. Example: public
     * @bodyParam options.youtube.title string Video title (max 100, no < or >). Empty = the caption's first 100 characters. Example: My new video
     * @bodyParam options.youtube.community_guidelines_accepted boolean The composer's confirmation that the video follows YouTube's Community Guidelines. Example: true
     * @bodyParam options.instagram.cover_url string Public cover image URL for an Instagram Reel.
     * @bodyParam options.linkedin.first_comment string A comment auto-posted right after publishing.
     * @bodyParam options.pinterest.board_ids object Board per Pinterest account, keyed by social_account_id (boards from GET /api/social/accounts/{id}/boards). Required to publish; never chosen for you.
     * @bodyParam options.pinterest.board_id string Board for a single Pinterest account, instead of board_ids. Example: 1234567890
     * @bodyParam options.pinterest.title string Pin title (max 100). Empty = no title.
     * @bodyParam options.pinterest.link string Where the Pin links to (max 2048). Empty = no link.
     * @bodyParam options.pinterest.alt_text string Image description for screen readers (max 500). Empty = none.
     * @bodyParam options.pinterest.cover_url string Cover image URL; required for a video Pin.
     */
    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $post = Auth::user()->posts()->create([
            'brand_id' => $data['brand_id'] ?? null,
            'caption' => $data['caption'] ?? null,
            'media' => $data['media'] ?? [],
            'status' => $data['status'] ?? Post::STATUS_DRAFT,
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'shorten_links' => (bool) ($data['shorten_links'] ?? false),
        ]);

        $data = $this->applyShortLinks($post, $data);
        if ($post->isDirty()) {
            $post->save();
        }

        $this->syncTargets($post, $data);
        $this->syncComments($post, $data);

        if ($post->status === Post::STATUS_POSTED) {
            $this->dispatchPublishing($post);
        }

        return response()->json($post->load('targets.socialAccount', 'comments.targetComments'), 201);
    }

    public function update(Request $request, string $id)
    {
        $post = Auth::user()->posts()->findOrFail($id);
        $data = $this->validatePayload($request, $post);

        $wasPosted = $post->status === Post::STATUS_POSTED;

        if (array_key_exists('shorten_links', $data)) {
            $post->shorten_links = (bool) $data['shorten_links'];
        }
        $data = $this->applyShortLinks($post, $data);

        if (array_key_exists('brand_id', $data)) $post->brand_id = $data['brand_id'];
        if (array_key_exists('caption', $data)) $post->caption = $data['caption'];
        if (array_key_exists('media', $data)) $post->media = $data['media'];
        if (array_key_exists('status', $data) && $data['status'] !== null) $post->status = $data['status'];
        if (array_key_exists('scheduled_at', $data)) $post->scheduled_at = $data['scheduled_at'];
        $post->save();

        if (array_key_exists('platforms', $data) || array_key_exists('targets', $data)) {
            $this->upsertTargets($post, $data);
        } elseif (array_key_exists('options', $data)) {
            // Options alone (e.g. the AI adding a Pinterest board to a saved
            // draft): store them on the post's existing targets, or they'd
            // pass validation and then never reach the publish job.
            foreach ($post->targets()->get() as $target) {
                if (array_key_exists($target->platform, $data['options'] ?? [])) {
                    $target->update(['options' => $data['options'][$target->platform]]);
                }
            }
        }

        if (array_key_exists('comments', $data)) {
            $this->syncComments($post, $data);
        }

        // Newly transitioned to posted -> kick off publishing.
        if (! $wasPosted && $post->status === Post::STATUS_POSTED) {
            $this->dispatchPublishing($post);
        }

        return $post->load('targets.socialAccount', 'comments.targetComments');
    }

    public function destroy(string $id)
    {
        $post = Auth::user()->posts()->findOrFail($id);
        $post->delete();

        return response()->noContent();
    }

    /**
     * Re-queue publishing for a single failed platform target. Only a FAILED
     * target can be retried, and only that target is touched — its siblings
     * (which may already be published) are left alone, so a retry can never
     * double-post to a platform that already succeeded.
     */
    public function retryTarget(string $id, string $targetId)
    {
        $post = Auth::user()->posts()->findOrFail($id);
        /** @var PostTarget $target */
        $target = $post->targets()->findOrFail($targetId);

        if ($target->status !== PostTarget::STATUS_FAILED) {
            abort(response()->json([
                'message' => 'Only a failed platform can be retried.',
            ], 422));
        }

        // Clear the previous error and hand back to the dispatcher. dispatchTarget
        // flips the target to publishing and queues the platform's job.
        $target->forceFill([
            'status' => PostTarget::STATUS_PENDING,
            'error' => null,
        ])->save();

        // A retry opens a new failure episode: if this attempt fails too, the
        // user should be emailed again (see PostFailureNotifier).
        $post->forceFill(['failure_notified_at' => null])->save();

        if (! PostPublishDispatcher::dispatchTarget($target)) {
            $target->forceFill([
                'status' => PostTarget::STATUS_FAILED,
                'error' => 'No automated publisher is available for this platform yet.',
            ])->save();

            abort(response()->json([
                'message' => 'This platform has no automated publisher yet.',
            ], 422));
        }

        return $post->fresh('targets');
    }

    private function validatePayload(Request $request, ?Post $post = null): array
    {
        $data = $request->validate([
            'caption' => 'nullable|string',
            'media' => 'nullable|array',
            'media.*.type' => 'required_with:media|string|in:image,video',
            'media.*.url' => 'nullable|string|url',
            'media.*.path' => 'nullable|string',
            'media.*.g' => 'nullable|string',
            'status' => ['nullable', 'string', Rule::in([Post::STATUS_DRAFT, Post::STATUS_SCHEDULED, Post::STATUS_POSTED])],
            'scheduled_at' => 'nullable|date',
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('user_id', Auth::id())],
            'platforms' => 'nullable|array',
            'platforms.*' => 'string|max:40',
            'targets' => 'nullable|array',
            'targets.*.platform' => 'required_with:targets|string|max:40',
            'targets.*.social_account_id' => 'nullable|integer',
            'targets.*.caption_override' => 'nullable|string',
            'comments' => 'nullable|array|max:10',
            'comments.*.body' => 'required_with:comments|string',
            'comments.*.delay_seconds' => 'nullable|integer|min:0|max:7200',
            'shorten_links' => 'nullable|boolean',
            'overrides' => 'nullable|array',
            'options' => 'nullable|array',
        ]);

        // Pinterest fields go out exactly as typed, so their limits (Pinterest's
        // API spec) are checked even on drafts. A separate validator: nested
        // rules in the one above would strip every other platform's options
        // from the validated data.
        // YouTube's own title limit applies as typed (the upload would refuse more).
        Validator::make($request->input('options.youtube') ?? [], [
            'title' => 'nullable|string|max:'.\App\Services\Social\CaptionRules::YOUTUBE_TITLE_MAX,
            'community_guidelines_accepted' => 'nullable|boolean',
        ])->validate();

        Validator::make($request->input('options.pinterest') ?? [], [
            'board_id' => 'nullable|string|max:64',
            'board_ids' => 'nullable|array',
            'board_ids.*' => 'nullable|string|max:64',
            'title' => 'nullable|string|max:100',
            'link' => 'nullable|url|max:2048',
            'alt_text' => 'nullable|string|max:500',
            'cover_url' => 'nullable|url',
        ])->validate();

        // Back-compat: the old LinkedIn-only first_comment option becomes the
        // first composed comment (the generic system posts it now).
        $firstComment = trim((string) data_get($data, 'options.linkedin.first_comment', ''));
        if ($firstComment !== '' && empty($data['comments'])) {
            $data['comments'] = [['body' => $firstComment, 'delay_seconds' => 0]];
        }

        $this->validatePlatforms($data);
        $this->validateTargetAccounts($data['targets'] ?? null);

        // Honour the offset: "18:00+07:00" is 11:00 UTC. The datetime cast
        // alone keeps the wall-clock and drops the zone, so a REST caller in
        // UTC+7 had its post go out seven hours late. The SPA and MCP already
        // send UTC; this makes every caller behave the same.
        if (! empty($data['scheduled_at'])) {
            $data['scheduled_at'] = Carbon::parse($data['scheduled_at'])->utc();
        }

        // posts:publish-due only picks up scheduled posts that have a time, so
        // "scheduled" with no scheduled_at would sit there forever. Check the
        // state the request produces, so an update that nulls the time is
        // caught too, not just a create that never sent one.
        $resultingStatus = $data['status'] ?? $post?->status ?? Post::STATUS_DRAFT;
        $resultingTime = array_key_exists('scheduled_at', $data) ? $data['scheduled_at'] : $post?->scheduled_at;
        if ($resultingStatus === Post::STATUS_SCHEDULED && ! $resultingTime) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'A scheduled post needs a scheduled_at time (ISO-8601).',
            ]);
        }

        // A past time would publish on the scheduler's next tick, which is
        // almost never what the caller meant (the MCP tool refuses it too).
        // Checked when the request sets the time or moves the post into
        // scheduled; a caption edit on a scheduled post whose time just
        // passed is left to the scheduler. One minute of grace covers the
        // tick interval and browser/server clock skew.
        $enteringSchedule = array_key_exists('scheduled_at', $data) || $post?->status !== Post::STATUS_SCHEDULED;
        if ($resultingStatus === Post::STATUS_SCHEDULED && $enteringSchedule
            && Carbon::parse($resultingTime)->lt(now()->subMinute())) {
            throw ValidationException::withMessages([
                'scheduled_at' => 'scheduled_at is in the past. The current time is '
                    .now()->utc()->toIso8601ZuluString().' — pick a later time.',
            ]);
        }

        // Once a platform has the post live, the post can't go back to a draft
        // or be rescheduled: publishing again would double-post. Retrying a
        // single failed platform goes through retryTarget instead.
        if ($post && $resultingStatus !== Post::STATUS_POSTED) {
            $live = $post->targets()->where('status', PostTarget::STATUS_PUBLISHED)->pluck('platform')->unique();
            if ($live->isNotEmpty()) {
                abort(response()->json([
                    'message' => 'This post has already been published to '.$live->map(fn ($p) => config("social.platforms.{$p}.label", ucfirst($p)))->join(', ')
                        ." and can't be moved back to {$resultingStatus}.",
                ], 422));
            }
        }

        // Platform list for the publish-time checks below, whichever payload
        // shape was used (legacy platforms[] or account-explicit targets[]).
        // On update, fields absent from the payload fall back to the post's
        // saved state — publishing a saved draft must re-check what will
        // actually go out, not just what this request happens to carry.
        $platformList = $data['platforms']
            ?? (isset($data['targets']) ? array_column($data['targets'], 'platform')
                : ($post?->targets()->pluck('platform')->all() ?? []));

        $publishing = in_array($data['status'] ?? null, [Post::STATUS_SCHEDULED, Post::STATUS_POSTED], true);

        // Publish-time caption limits: a draft may be over-limit while it's
        // being written, but a doomed caption can never be scheduled/posted.
        if ($publishing) {
            $effectiveCaption = array_key_exists('caption', $data)
                ? (string) ($data['caption'] ?? '')
                : (string) ($post?->caption ?? '');
            $savedOverrides = $post
                ? $post->targets()->pluck('caption_override', 'platform')->filter()->all()
                : [];
            $effectiveOverrides = ($data['overrides'] ?? null) !== null ? $data['overrides'] : $savedOverrides;
            // An explicit YouTube title stands in for the caption; absent from the payload, the saved one counts.
            $youtubeTitle = array_key_exists('options', $data)
                ? data_get($data, 'options.youtube.title')
                : data_get($post?->targets()->where('platform', 'youtube')->first()?->options, 'title');

            foreach (array_unique($platformList) as $platform) {
                $override = $effectiveOverrides[$platform] ?? '';
                $text = $override !== '' && $override !== null ? (string) $override : $effectiveCaption;

                $errors = $platform === 'x'
                    ? \App\Services\Social\CaptionRules::xThreadErrors($text)
                    : array_filter([
                        \App\Services\Social\CaptionRules::titleError($platform, $text, $platform === 'youtube' ? $youtubeTitle : null),
                        \App\Services\Social\CaptionRules::captionError($platform, $text),
                    ]);

                if ($errors !== []) {
                    abort(response()->json(['message' => implode(' ', $errors)], 422));
                }
            }

            // Per-platform media rules (count, type, mixing) — resolved the
            // same way as captions: the payload's media, else what's saved.
            $effectiveMedia = array_key_exists('media', $data)
                ? ($data['media'] ?? [])
                : ($post?->media ?? []);

            foreach (array_unique($platformList) as $platform) {
                if ($error = MediaRules::error($platform, $effectiveMedia)) {
                    abort(response()->json(['message' => $error], 422));
                }
            }

            // Comments post as X replies, so each body must fit a tweet.
            if (in_array('x', $platformList, true)) {
                $effectiveComments = $data['comments']
                    ?? $post?->comments->map(fn ($c) => ['body' => $c->body])->all()
                    ?? [];
                foreach ($effectiveComments as $i => $comment) {
                    if (\App\Services\Social\CaptionRules::xLength((string) $comment['body']) > \App\Services\Social\CaptionRules::LIMITS['x']) {
                        abort(response()->json([
                            'message' => 'Comment '.($i + 1)." is over X's ".\App\Services\Social\CaptionRules::LIMITS['x'].' character limit.',
                        ], 422));
                    }
                }
            }
        }

        // TikTok accepts either a video or a photo slideshow (one or more images).
        $targetsTikTok = in_array('tiktok', $platformList, true);
        if ($publishing && $targetsTikTok) {
            $hasVideo = collect($data['media'] ?? [])
                ->contains(fn ($m) => ($m['type'] ?? null) === 'video' && (! empty($m['url']) || ! empty($m['path'])));
            $hasImages = collect($data['media'] ?? [])
                ->contains(fn ($m) => ($m['type'] ?? null) === 'image' && ! empty($m['url']));
            if (! $hasVideo && ! $hasImages) {
                abort(response()->json([
                    'message' => 'TikTok requires an uploaded video or one or more images before publishing or scheduling.',
                ], 422));
            }

            // TikTok audit compliance: the user must have explicitly chosen a
            // privacy level (no default), branded content cannot be private,
            // and disclosing commercial content requires at least one of the
            // brand options. Mirrors the composer's publish gating so a direct
            // API caller is held to the same rules.
            $tt = $data['options']['tiktok'] ?? [];
            $privacy = $tt['privacy_level'] ?? null;
            $allowedPrivacy = ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'];
            if (empty($privacy) || ! in_array($privacy, $allowedPrivacy, true)) {
                abort(response()->json([
                    'message' => 'Choose who can view your TikTok post before publishing.',
                ], 422));
            }
            if (! empty($tt['branded_content']) && $privacy === 'SELF_ONLY') {
                abort(response()->json([
                    'message' => 'Branded content visibility cannot be set to private on TikTok.',
                ], 422));
            }
            if (! empty($tt['disclose_commercial']) && empty($tt['your_brand']) && empty($tt['branded_content'])) {
                abort(response()->json([
                    'message' => 'Indicate whether your content promotes yourself, a third party, or both.',
                ], 422));
            }
        }

        // YouTube uploads the stored file directly, so it needs a video with a disk path.
        $targetsYouTube = in_array('youtube', $platformList, true);
        if ($publishing && $targetsYouTube) {
            $hasVideoPath = collect($data['media'] ?? [])
                ->contains(fn ($m) => ($m['type'] ?? null) === 'video' && ! empty($m['path']));
            if (! $hasVideoPath) {
                abort(response()->json([
                    'message' => 'YouTube requires an uploaded video before publishing or scheduling.',
                ], 422));
            }
        }

        // Every Pin is an image or a video; Pinterest has no text-only posts.
        if ($publishing && in_array('pinterest', $platformList, true)) {
            $hasMedia = collect($data['media'] ?? $post?->media ?? [])
                ->contains(fn ($m) => in_array($m['type'] ?? null, ['image', 'video'], true) && ! empty($m['url']));
            if (! $hasMedia) {
                abort(response()->json([
                    'message' => 'Pinterest needs an image or a video before publishing or scheduling.',
                ], 422));
            }

            // No board is ever picked for the user: every Pinterest target
            // needs one chosen for its own account (a board belongs to one
            // account), or the single board_id when no account is pinned.
            $pin = $data['options']['pinterest']
                ?? $post?->targets()->where('platform', 'pinterest')->value('options')
                ?? [];
            if (isset($data['targets'])) {
                $pinTargets = array_filter($data['targets'], fn ($t) => $t['platform'] === 'pinterest');
            } elseif (isset($data['platforms'])) {
                $pinTargets = [['platform' => 'pinterest']];
            } else {
                $pinTargets = $post?->targets()->where('platform', 'pinterest')->get(['social_account_id'])->toArray() ?? [];
            }
            foreach ($pinTargets as $t) {
                $accountId = $t['social_account_id'] ?? null;
                $board = ($accountId !== null ? ($pin['board_ids'][(string) $accountId] ?? null) : null) ?? ($pin['board_id'] ?? null);
                if (blank($board)) {
                    abort(response()->json([
                        'message' => 'Choose a Pinterest board for every Pinterest account before publishing or scheduling.',
                    ], 422));
                }
            }
        }

        return $data;
    }

    /**
     * Every requested platform must have a publisher and be switched on;
     * otherwise its target would sit pending forever. Same rule the MCP
     * create_post tool applies before delegating here.
     */
    private function validatePlatforms(array $data): void
    {
        $requested = array_unique(array_merge(
            $data['platforms'] ?? [],
            array_column($data['targets'] ?? [], 'platform'),
        ));

        foreach ($requested as $platform) {
            if (! in_array($platform, PostPublishDispatcher::platforms(), true)) {
                abort(response()->json([
                    'message' => "Unsupported platform: {$platform}. Supported platforms: "
                        .implode(', ', PostPublishDispatcher::platforms()).'.',
                ], 422));
            }
            if (config("social.platforms.{$platform}.enabled", true) === false) {
                $label = config("social.platforms.{$platform}.label", ucfirst($platform));
                abort(response()->json([
                    'message' => "{$label} isn't available on ViewsMax right now.",
                ], 422));
            }
        }
    }

    /**
     * Targets may pin a specific connected account (multi-account platforms).
     * Every pinned account must belong to the caller and match its platform;
     * the same account (or the same legacy platform entry) can't repeat.
     */
    private function validateTargetAccounts(?array $targets): void
    {
        if (! $targets) {
            return;
        }

        $seen = [];
        $accountIds = array_filter(array_column($targets, 'social_account_id'));
        $accounts = \App\Models\SocialAccount::whereIn('id', $accountIds)
            ->where('user_id', Auth::id())
            ->get()
            ->keyBy('id');

        foreach ($targets as $entry) {
            $platform = $entry['platform'];
            $accountId = $entry['social_account_id'] ?? null;

            if ($accountId !== null) {
                $account = $accounts->get($accountId);
                if (! $account) {
                    abort(response()->json(['message' => 'One of the selected accounts is not connected to your profile.'], 422));
                }
                if ($account->platform !== $platform) {
                    abort(response()->json(['message' => "The selected account doesn't belong to {$platform}."], 422));
                }
            }

            $key = $platform.'#'.($accountId ?? 'legacy');
            if (isset($seen[$key])) {
                abort(response()->json(['message' => 'The same account is targeted more than once.'], 422));
            }
            $seen[$key] = true;
        }
    }

    private function syncTargets(Post $post, array $data): void
    {
        $overrides = $data['overrides'] ?? [];
        $options = $data['options'] ?? [];

        foreach ($this->targetEntries($data) as $entry) {
            $platform = $entry['platform'];
            $post->targets()->create([
                'platform' => $platform,
                'social_account_id' => $entry['social_account_id'] ?? null,
                'caption_override' => $entry['caption_override'] ?? $overrides[$platform] ?? null,
                'options' => $options[$platform] ?? null,
                'status' => PostTarget::STATUS_PENDING,
            ]);
        }
    }

    /**
     * Reconcile a post's targets with the requested set WITHOUT recreating
     * rows that survive: an existing target keeps its id, status, publish
     * result and meta (e.g. X thread resume state) — only its caption
     * override/options refresh. Removed platforms/accounts are deleted, new
     * ones created.
     */
    private function upsertTargets(Post $post, array $data): void
    {
        $overrides = $data['overrides'] ?? [];
        $options = $data['options'] ?? [];

        $keyOf = fn (string $platform, $accountId) => $platform.'#'.($accountId ?? 'legacy');
        $existing = $post->targets()->get()->keyBy(fn (PostTarget $t) => $keyOf($t->platform, $t->social_account_id));
        $kept = [];

        foreach ($this->targetEntries($data) as $entry) {
            $platform = $entry['platform'];
            $accountId = $entry['social_account_id'] ?? null;
            $key = $keyOf($platform, $accountId);
            $kept[$key] = true;

            $attrs = [
                'caption_override' => $entry['caption_override'] ?? $overrides[$platform] ?? null,
                'options' => $options[$platform] ?? null,
            ];

            if ($target = $existing->get($key)) {
                $target->fill($attrs)->save();
            } else {
                $post->targets()->create($attrs + [
                    'platform' => $platform,
                    'social_account_id' => $accountId,
                    'status' => PostTarget::STATUS_PENDING,
                ]);
            }
        }

        // A published target is the record of a live post: it is never deleted
        // (and so never recreated as a fresh pending target that could
        // publish again) even when the request leaves it out.
        foreach ($existing as $key => $target) {
            if (! isset($kept[$key]) && $target->status !== PostTarget::STATUS_PUBLISHED) {
                $target->delete();
            }
        }
    }

    /**
     * Normalized target entries from either payload shape. Account-explicit
     * targets[] wins; legacy platforms[] maps to targets without a pinned
     * account (the publish job resolves the newest connected one).
     */
    private function targetEntries(array $data): array
    {
        return $data['targets']
            ?? array_map(fn ($p) => ['platform' => $p], $data['platforms'] ?? []);
    }

    /**
     * When the post opted into link shortening, swap every http(s) URL in the
     * caption / overrides / comment bodies for a tracked /l/{slug} redirect.
     * Idempotent across edits (same destination → same slug). Applied after
     * the post row exists (slugs are pinned to the post) and after validation
     * — a shortened caption is never longer than the original on any platform
     * (X weighs all URLs at a flat 23 chars either way).
     */
    private function applyShortLinks(Post $post, array $data): array
    {
        if (! $post->shorten_links) {
            return $data;
        }

        $shortened = app(\App\Services\ShortLinkService::class)->shortenPayload(Auth::user(), $post, $data);

        if (($shortened['caption'] ?? null) !== ($data['caption'] ?? null)) {
            $post->caption = $shortened['caption'];
        }

        return $shortened;
    }

    /**
     * Replace the post's composed comments with the requested set. Delivery
     * rows for already-published targets are left alone — editing comments on
     * a live post only affects targets that haven't posted them yet.
     */
    private function syncComments(Post $post, array $data): void
    {
        if (! array_key_exists('comments', $data)) {
            return;
        }

        $post->comments()->delete();
        foreach (array_values($data['comments'] ?? []) as $i => $comment) {
            $post->comments()->create([
                'position' => $i,
                'body' => $comment['body'],
                'delay_seconds' => (int) ($comment['delay_seconds'] ?? 0),
            ]);
        }
    }

    /**
     * Dispatch publish jobs for every target we have a publisher for. See
     * PostPublishDispatcher for the platform map. A target that is already
     * published is skipped so a re-save can never double-post.
     */
    private function dispatchPublishing(Post $post): void
    {
        foreach ($post->targets()->get() as $target) {
            if ($target->status !== PostTarget::STATUS_PUBLISHED) {
                PostPublishDispatcher::dispatchTarget($target);
            }
        }
    }
}
