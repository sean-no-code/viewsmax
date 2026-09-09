<?php

namespace App\Http\Controllers;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Services\Automations\AutomationLog;
use App\Services\Automations\AutomationSubscriptionService;
use App\Services\Automations\KeywordMatcher;
use App\Services\Social\Providers\InstagramProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * @group Automations
 *
 * ManyChat-style Instagram automations: when someone comments on a post /
 * replies to a story / sends a DM (optionally containing keywords), reply
 * publicly and/or send a DM with a tracked link. Everything is scoped to the
 * authenticated user; the connected account must carry the comments +
 * messages scopes (else 422 `reconnect_required`).
 */
class AutomationController extends Controller
{
    public function __construct(
        protected SocialProviderManager $manager,
        protected AutomationSubscriptionService $subscriptions,
    ) {}

    /** All of the user's automations with run / DM / click stats. */
    public function index(Request $request): JsonResponse
    {
        $query = Auth::user()->automations()->with('socialAccount')->withStats();

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        if ($trigger = $request->query('trigger_type')) {
            $query->whereIn('trigger_type', array_intersect((array) $trigger, Automation::TRIGGERS));
        }
        if ($status = $request->query('status')) {
            $query->whereIn('status', array_intersect((array) $status, Automation::STATUSES));
        }

        $sort = in_array($request->query('sort'), ['name', 'updated_at', 'runs_count', 'created_at'], true) ? $request->query('sort') : 'updated_at';
        $dir = $request->query('dir') === 'asc' ? 'asc' : 'desc';
        $automations = $query->orderBy($sort, $dir)->get();

        return $this->ok('OK', [
            'automations' => $automations->map(fn (Automation $a) => $this->serialize($a))->values(),
            'enabled' => (bool) config('social.platforms.instagram.automations_enabled'),
            'limit' => $this->limit(),
            'used' => Auth::user()->automations()->count(),
        ]);
    }

    /** Instagram accounts eligible for automations + their scope status. */
    public function accounts(): JsonResponse
    {
        $accounts = Auth::user()->socialAccounts()->where('platform', 'instagram')->orderBy('id')->get();

        return $this->ok('OK', [
            'accounts' => $accounts->map(fn (SocialAccount $a) => $this->serializeAccount($a))->values(),
            'enabled' => (bool) config('social.platforms.instagram.automations_enabled'),
            'limit' => $this->limit(),
            'used' => Auth::user()->automations()->count(),
        ]);
    }

    /** The account's own posts/reels for the post picker (cached 5 min). */
    public function media(Request $request): JsonResponse
    {
        $data = $request->validate([
            'social_account_id' => 'required|integer',
            'after' => 'nullable|string|max:512',
            'refresh' => 'nullable|boolean',
        ]);

        $account = Auth::user()->socialAccounts()->where('platform', 'instagram')->findOrFail($data['social_account_id']);
        $key = sprintf('ig-media:%d:%s', $account->id, md5((string) ($data['after'] ?? '')));

        if (! empty($data['refresh'])) {
            Cache::forget($key);
        }

        try {
            $hit = Cache::has($key);
            $page = Cache::remember($key, now()->addMinutes(5), function () use ($account, $data) {
                /** @var InstagramProvider $provider */
                $provider = $this->manager->for('instagram');

                return $provider->listMedia($account, $data['after'] ?? null, 24);
            });
            AutomationLog::debug('media picker', AutomationLog::context(account: $account) + ['cache_hit' => $hit, 'after' => $data['after'] ?? null]);
        } catch (RuntimeException $e) {
            if ($account->fresh()?->status === SocialAccount::STATUS_NEEDS_REAUTH) {
                return $this->reconnectRequired($account);
            }

            return response()->json(['success' => false, 'message' => 'Instagram could not load your posts right now. '.$e->getMessage()], 422);
        }

        return $this->ok('OK', $page);
    }

    public function store(Request $request): JsonResponse
    {
        if (! config('social.platforms.instagram.automations_enabled')) {
            return response()->json(['success' => false, 'message' => 'Instagram automations are not enabled on this server yet.'], 422);
        }

        $account = Auth::user()->socialAccounts()->where('platform', 'instagram')->findOrFail((int) $request->input('social_account_id'));

        // Plan cap — same shape as offers: no active subscription falls back
        // to the Free tier's cap; NULL = unlimited.
        $plan = Auth::user()->activePlan() ?? Plan::where('name', Plan::FREE_PLAN)->where('is_active', true)->first();
        if ($plan && $plan->max_automations !== null && Auth::user()->automations()->count() >= $plan->max_automations) {
            return response()->json([
                'success' => false,
                'message' => "You've reached your plan's limit of {$plan->max_automations} automation(s). "
                    .'Delete an existing automation or upgrade your plan to add more.',
            ], 422);
        }

        if (! $account->hasScopes(Automation::REQUIRED_IG_SCOPES)) {
            return $this->reconnectRequired($account);
        }

        $data = $this->validated($request, $account);

        $automation = Auth::user()->automations()->create($data + [
            'social_account_id' => $account->id,
            'platform' => 'instagram',
            'status' => Automation::STATUS_STOPPED,
        ]);

        AutomationLog::info('automation created', AutomationLog::context($automation));

        return $this->ok('Automation created.', $this->serialize($this->reload($automation)), 201);
    }

    public function show(int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->with('socialAccount')->withStats()->findOrFail($id);
        $runs = $automation->runs()->latest('id')->limit(20)->get();

        return $this->ok('OK', $this->serialize($automation) + ['recent_runs' => $runs]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->findOrFail($id);
        $account = $automation->socialAccount;

        if ($request->filled('social_account_id') && (int) $request->input('social_account_id') !== $automation->social_account_id) {
            if ($automation->runs()->exists()) {
                return response()->json(['success' => false, 'message' => 'The account cannot be changed once an automation has run. Create a new automation instead.'], 422);
            }
            $account = Auth::user()->socialAccounts()->where('platform', 'instagram')->findOrFail((int) $request->input('social_account_id'));
        }

        $data = $this->validated($request, $account, $automation);
        $automation->fill($data + ['social_account_id' => $account->id])->save();

        AutomationLog::info('automation updated', AutomationLog::context($automation));

        return $this->ok('Automation saved.', $this->serialize($this->reload($automation)));
    }

    public function destroy(int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->findOrFail($id);
        $automation->delete();

        AutomationLog::info('automation deleted', AutomationLog::context($automation));

        return $this->ok('Automation deleted.');
    }

    /** Go live: needs scopes + usable token, and a webhook subscription. */
    public function start(int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->findOrFail($id);
        $account = $automation->socialAccount;

        if (! config('social.platforms.instagram.automations_enabled')) {
            return response()->json(['success' => false, 'message' => 'Instagram automations are not enabled on this server yet.'], 422);
        }
        if (! $account || ! $account->canRunAutomations()) {
            return $this->reconnectRequired($account);
        }

        try {
            $this->subscriptions->ensureSubscribed($account);
        } catch (RuntimeException $e) {
            AutomationLog::error('automation start refused: subscription failed', AutomationLog::context($automation) + ['error' => $e->getMessage()]);

            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $automation->forceFill(['status' => Automation::STATUS_LIVE, 'last_error' => null])->save();
        AutomationLog::info('automation started', AutomationLog::context($automation));

        return $this->ok('Automation is live.', $this->serialize($this->reload($automation)));
    }

    public function stop(int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->findOrFail($id);
        $automation->forceFill(['status' => Automation::STATUS_STOPPED])->save();

        AutomationLog::info('automation stopped', AutomationLog::context($automation));

        return $this->ok('Automation stopped.', $this->serialize($this->reload($automation)));
    }

    /** Paginated runs, newest first. */
    public function runs(Request $request, int $id): JsonResponse
    {
        $automation = Auth::user()->automations()->findOrFail($id);
        $runs = $automation->runs()->latest('id')->paginate(25);

        return $this->ok('OK', $runs);
    }

    /* ------------------------------------------------------------------ */

    /**
     * Validate + normalize the editable fields. Keywords are lowercased/
     * de-duped; a button forces the 80-char card-title limit on dm_text.
     *
     * @return array<string, mixed>
     */
    protected function validated(Request $request, SocialAccount $account, ?Automation $existing = null): array
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'trigger_type' => [$existing ? 'sometimes' : 'required', Rule::in(Automation::TRIGGERS)],
            'post_match' => ['nullable', Rule::in(Automation::POST_MATCHES)],
            'posts' => 'nullable|array|max:'.Automation::MAX_POSTS,
            'posts.*.id' => 'required|string|max:64',
            'posts.*.media_type' => 'nullable|string|max:20',
            'posts.*.thumbnail_url' => 'nullable|string|max:1024',
            'posts.*.permalink' => 'nullable|string|max:1024',
            'posts.*.caption' => 'nullable|string|max:300',
            'include_replies' => 'nullable|boolean',
            'keyword_mode' => ['nullable', Rule::in(Automation::KEYWORD_MODES)],
            'keywords' => 'nullable|array|max:'.Automation::MAX_KEYWORDS,
            'keywords.*' => 'string|max:60',
            'cooldown_hours' => 'nullable|integer|min:0|max:720',
            'reply_enabled' => 'nullable|boolean',
            'reply_texts' => 'nullable|array|max:'.Automation::MAX_REPLY_TEXTS,
            'reply_texts.*' => 'string|max:2200',
            'dm_text' => [$existing ? 'sometimes' : 'required', 'string', 'max:'.Automation::DM_TEXT_MAX],
            'dm_subtitle' => 'nullable|string|max:'.Automation::CARD_SUBTITLE_MAX,
            'dm_image_url' => 'nullable|url|starts_with:https://|max:1024',
            'dm_button_label' => 'nullable|string|max:'.Automation::BUTTON_LABEL_MAX.'|required_with:dm_button_url',
            'dm_button_url' => 'nullable|url|required_with:dm_button_label',
        ]);

        $trigger = $data['trigger_type'] ?? $existing?->trigger_type;
        $keywordMode = $data['keyword_mode'] ?? $existing?->keyword_mode ?? Automation::KEYWORD_ANY;
        $dmText = array_key_exists('dm_text', $data) ? $data['dm_text'] : $existing?->dm_text;
        $buttonUrl = array_key_exists('dm_button_url', $data) ? $data['dm_button_url'] : $existing?->dm_button_url;

        $fail = fn (string $message) => abort(response()->json(['success' => false, 'message' => $message], 422));

        if ($trigger === Automation::TRIGGER_COMMENT) {
            $postMatch = $data['post_match'] ?? $existing?->post_match;
            if (! $postMatch) {
                $fail('Choose which posts the automation listens on (a specific post or any post).');
            }
            $posts = array_key_exists('posts', $data) ? $data['posts'] : $existing?->posts;
            if ($postMatch === Automation::POST_MATCH_SPECIFIC && empty($posts)) {
                $fail('Pick at least one post or reel, or switch to "any post or reel".');
            }
            $data['post_match'] = $postMatch;
            if ($postMatch === Automation::POST_MATCH_ANY) {
                $data['posts'] = null;
            }
        } else {
            $data['post_match'] = null;
            $data['posts'] = null;
            if (! empty($data['reply_enabled'])) {
                $fail('Public replies are only available for comment automations.');
            }
            $data['reply_enabled'] = false;
        }

        if ($keywordMode !== Automation::KEYWORD_ANY) {
            $keywords = KeywordMatcher::normalizeList(array_key_exists('keywords', $data) ? ($data['keywords'] ?? []) : ($existing?->keywords ?? []));
            if ($keywords === []) {
                $fail('Add at least one keyword, or switch to "any word".');
            }
            $data['keywords'] = $keywords;
        } else {
            $data['keywords'] = null;
        }
        $data['keyword_mode'] = $keywordMode;

        if (! empty($data['reply_enabled'])) {
            $texts = array_values(array_filter(array_map('trim', array_key_exists('reply_texts', $data) ? ($data['reply_texts'] ?? []) : ($existing?->reply_texts ?? []))));
            if ($texts === []) {
                $fail('Add the comment reply text (or turn the public reply off).');
            }
            $data['reply_texts'] = $texts;
        }

        if ($buttonUrl && mb_strlen((string) $dmText) > Automation::CARD_TITLE_MAX) {
            $fail('With a button the DM is sent as a card, and Instagram limits the card text to '.Automation::CARD_TITLE_MAX.' characters. Shorten the message or remove the button.');
        }

        if (array_key_exists('name', $data) && trim((string) $data['name']) === '') {
            $data['name'] = 'Untitled';
        }

        return $data;
    }

    protected function limit(): ?int
    {
        $plan = Auth::user()->activePlan() ?? Plan::where('name', Plan::FREE_PLAN)->where('is_active', true)->first();

        return $plan?->max_automations;
    }

    protected function reload(Automation $automation): Automation
    {
        return Automation::with('socialAccount')->withStats()->findOrFail($automation->id);
    }

    /** @return array<string, mixed> */
    protected function serialize(Automation $a): array
    {
        $account = $a->socialAccount;

        return $a->toArray() + [
            'trigger_summary' => $a->triggerSummary(),
            'stats' => [
                'runs' => (int) ($a->runs_count ?? 0),
                'dms_sent' => (int) ($a->dms_sent_count ?? 0),
                'clicked' => (int) ($a->clicked_count ?? 0),
                'ctr' => $a->ctr(),
            ],
            'needs_reconnect' => ! $account || ! $account->canRunAutomations(),
            'social_account' => $account ? $this->serializeAccount($account) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function serializeAccount(SocialAccount $a): array
    {
        return [
            'id' => $a->id,
            'platform' => $a->platform,
            'username' => $a->username,
            'name' => $a->name,
            'avatar_url' => $a->avatar_url,
            'status' => $a->status,
            'token_valid' => $a->hasUsableCredentials(),
            'has_messaging_scopes' => $a->hasScopes(Automation::REQUIRED_IG_SCOPES),
            'missing_scopes' => $a->missingAutomationScopes(),
            'can_automate' => $a->canRunAutomations(),
            'webhook_subscribed_at' => $a->webhook_subscribed_at?->toISOString(),
        ];
    }

    protected function reconnectRequired(?SocialAccount $account): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => 'reconnect_required',
            'message' => 'Reconnect your Instagram account to allow the comments and messages permissions automations need.',
            'social_account_id' => $account?->id,
            'missing_scopes' => $account?->missingAutomationScopes() ?? Automation::REQUIRED_IG_SCOPES,
        ], 422);
    }

    protected function ok(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
