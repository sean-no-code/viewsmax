<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * @group Admin — Users
 *
 * Admin index of users plus signup / added-card widgets over a date range.
 * Gated by the `role:admin` middleware on the route group; declared again here
 * as defence in depth.
 */
class UserAdminController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }

    // Columns the client may sort by → how that maps to SQL. Anything else
    // falls back to created_at, so an unknown ?sort can't inject.
    private const SORTABLE = [
        'name', 'email', 'created_at', 'has_card', 'plan', 'last_login_at',
        'posts_count', 'accounts_count', 'offers_count', 'first_action_at', 'subscription_cancelled_at',
    ];

    /**
     * When the user first DID something (created a post or an offer) — the
     * activation moment, measured against users.created_at. Soft-deleted
     * offers still count: a deleted offer was still an action. ANSI CASE
     * instead of LEAST()/min(a,b) so SQLite tests and Postgres prod agree.
     */
    private function firstActionSql(): string
    {
        $firstPost = '(select min(p.created_at) from posts p where p.user_id = users.id)';
        $firstOffer = '(select min(o.created_at) from tracking_events o where o.user_id = users.id)';

        return "(case when {$firstPost} is null then {$firstOffer}"
            ." when {$firstOffer} is null then {$firstPost}"
            ." when {$firstPost} < {$firstOffer} then {$firstPost}"
            ." else {$firstOffer} end)";
    }

    /**
     * cancelled_at of the user's LATEST subscription row. Latest by id — a
     * re-subscribe inserts a fresh row (cancelled_at null) and a reactivation
     * nulls the same row, so this reads "currently cancelled", not "was ever
     * cancelled". starts_at is nullable, so id is the only reliable recency key.
     */
    private function latestCancelledAtSql(): string
    {
        return '(select up.cancelled_at from user_plans up where up.user_id = users.id order by up.id desc limit 1)';
    }

    /**
     * Paginated index of users. Filters (name, email, created_at range, card,
     * plan) combine with AND and apply server-side; sorting is allowlisted.
     */
    public function index(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $perPage = min(100, max(10, (int) $request->input('per_page', 25)));
        $statuses = User::ACTIVE_SUBSCRIPTION_STATUSES;

        $query = User::query()
            ->select('users.*') // explicit: the addSelects below would otherwise drop the default *
            ->whereBetween('users.created_at', [$from, $to])
            ->with(['plans' => fn ($q) => $q->wherePivotIn('status', $statuses)])
            ->withCount([
                'posts',
                'posts as posts_posted_count' => fn ($q) => $q->where('status', Post::STATUS_POSTED),
                'offers',
            ])
            // Connected accounts span both stores (legacy connections + social_accounts).
            ->addSelect(DB::raw(
                '((select count(*) from connections c where c.user_id = users.id)'
                .' + (select count(*) from social_accounts sa where sa.user_id = users.id)) as accounts_count'
            ))
            ->addSelect(DB::raw($this->firstActionSql().' as first_action_at'))
            ->addSelect(DB::raw($this->latestCancelledAtSql().' as subscription_cancelled_at'));

        if ($name = trim((string) $request->input('name', ''))) {
            $query->where('users.name', 'like', "%{$name}%");
        }
        if ($email = trim((string) $request->input('email', ''))) {
            $query->where('users.email', 'like', "%{$email}%");
        }

        // Card multi-select: yes = card details were actually entered
        // (card_added_at — NOT stripe_customer_id, which exists as soon as a
        // checkout session is built, before the card screen). Selecting both
        // (or neither) is a no-op filter.
        $card = $this->csv($request->input('card'));
        if (count($card) === 1) {
            $card[0] === 'yes'
                ? $query->whereNotNull('users.card_added_at')
                : $query->whereNull('users.card_added_at');
        }

        // Plan multi-select: user has an active/trialing plan in the chosen set.
        $plans = $this->csv($request->input('plan'));
        if (! empty($plans)) {
            $query->whereHas('plans', fn ($q) => $q
                ->whereIn('user_plans.status', $statuses)
                ->whereIn('plans.name', $plans));
        }

        // Cancelled multi-select: yes = latest subscription row is cancelled.
        // Selecting both (or neither) is a no-op filter, like card.
        $cancelled = $this->csv($request->input('cancelled'));
        if (count($cancelled) === 1) {
            $query->whereRaw($this->latestCancelledAtSql().($cancelled[0] === 'yes' ? ' is not null' : ' is null'));
        }

        // Sorting (allowlisted column + direction).
        $dir = strtolower((string) $request->input('dir')) === 'asc' ? 'asc' : 'desc';
        $sort = in_array($request->input('sort'), self::SORTABLE, true) ? $request->input('sort') : 'created_at';
        match ($sort) {
            'name' => $query->orderBy('users.name', $dir),
            'email' => $query->orderBy('users.email', $dir),
            'has_card' => $query->orderByRaw('users.card_added_at is null '.$dir),
            'last_login_at' => $query->orderByRaw('users.last_login_at is null, users.last_login_at '.$dir),
            'plan' => $query->orderByRaw(
                '(select p.name from user_plans up join plans p on p.id = up.plan_id'
                .' where up.user_id = users.id and up.status in ('
                .implode(',', array_fill(0, count($statuses), '?')).') limit 1) '.$dir,
                $statuses
            ),
            // withCount/addSelect aliases — a bare alias in ORDER BY is valid
            // on both SQLite and Postgres (an alias inside an expression isn't,
            // hence the repeated subqueries for the two timestamp sorts below).
            'posts_count', 'accounts_count', 'offers_count' => $query->orderBy($sort, $dir),
            'first_action_at' => $query->orderByRaw(
                $this->firstActionSql().' is null, '.$this->firstActionSql().' '.$dir
            ),
            'subscription_cancelled_at' => $query->orderByRaw(
                $this->latestCancelledAtSql().' is null, '.$this->latestCancelledAtSql().' '.$dir
            ),
            default => $query->orderBy('users.created_at', $dir),
        };

        $users = $query->paginate($perPage);

        $rows = collect($users->items())->map(function (User $u) {
            $plan = $u->plans->first(); // already scoped to active/trialing
            return [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'created_at' => optional($u->created_at)->toISOString(),
                'last_login_at' => optional($u->last_login_at)->toISOString(),
                'last_login_country' => $u->last_login_country,
                'last_login_country_code' => $u->last_login_country_code,
                'has_card' => ! is_null($u->card_added_at),
                'subscribed' => ! is_null($plan),
                'plan' => $plan?->name,
                'onboarded' => ! is_null($u->onboarding_completed_at),
                'posts_count' => (int) $u->posts_count,
                'posts_posted_count' => (int) $u->posts_posted_count,
                'accounts_count' => (int) $u->accounts_count,
                'offers_count' => (int) $u->offers_count,
                // Raw addSelect timestamps arrive as driver strings, not Carbon.
                'first_action_at' => $u->first_action_at ? Carbon::parse($u->first_action_at)->toISOString() : null,
                'subscription_cancelled_at' => $u->subscription_cancelled_at ? Carbon::parse($u->subscription_cancelled_at)->toISOString() : null,
            ];
        });

        return response()->json(['data' => [
            'users' => $rows,
            'total' => $users->total(),
            'page' => $users->currentPage(),
            'per_page' => $users->perPage(),
            'last_page' => $users->lastPage(),
            'sort' => $sort,
            'dir' => $dir,
        ]]);
    }

    /**
     * Typeahead source for the name / email / plan filters — distinct existing
     * values matching an optional query.
     */
    public function suggest(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $like = fn ($builder, string $col) => $builder->when($q !== '', fn ($w) => $w->where($col, 'like', "%{$q}%"));

        $values = match ($request->input('field')) {
            'name' => $like(User::query()->whereNotNull('name')->where('name', '!=', ''), 'users.name')
                ->orderBy('name')->distinct()->limit(10)->pluck('name'),
            'email' => $like(User::query(), 'users.email')
                ->orderBy('email')->distinct()->limit(10)->pluck('email'),
            'plan' => $like(\App\Models\Plan::query(), 'name')
                ->orderBy('name')->distinct()->limit(50)->pluck('name'),
            default => collect(),
        };

        return response()->json(['data' => $values->values()]);
    }

    /** Normalise a filter param that may arrive as an array or a CSV string. */
    private function csv($value): array
    {
        if (is_array($value)) {
            $parts = $value;
        } elseif (is_string($value) && $value !== '') {
            $parts = explode(',', $value);
        } else {
            return [];
        }

        return array_values(array_filter(array_map('trim', $parts), fn ($v) => $v !== ''));
    }

    /**
     * Signup / added-card / subscribed counts + a daily series for the widgets.
     * "Added card" = card details were actually entered (card_added_at set at
     * checkout completion / first paid invoice) — a bare Stripe customer only
     * proves a checkout session was created.
     */
    public function stats(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $byDay = fn ($q) => $q->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as day, COUNT(*) as c')
            ->groupBy('day')
            ->pluck('c', 'day');

        $signups = $byDay(User::query());
        $cards = $byDay(User::query()->whereNotNull('card_added_at'));
        $subs = $byDay(User::query()->whereHas('plans', fn ($q) => $q->whereIn('user_plans.status', User::ACTIVE_SUBSCRIPTION_STATUSES)));

        // Continuous daily series so the sparklines have no gaps.
        $series = [];
        for ($d = $from->copy(); $d <= $to; $d->addDay()) {
            $key = $d->format('Y-m-d');
            $series[] = [
                'date' => $key,
                'signups' => (int) ($signups[$key] ?? 0),
                'added_card' => (int) ($cards[$key] ?? 0),
                'subscribed' => (int) ($subs[$key] ?? 0),
            ];
        }

        return response()->json(['data' => [
            'from' => $from->toISOString(),
            'to' => $to->toISOString(),
            'totals' => [
                'signups' => (int) $signups->sum(),
                'added_card' => (int) $cards->sum(),
                'subscribed' => (int) $subs->sum(),
            ],
            'series' => $series,
        ]]);
    }

    /**
     * Resolve the [from, to] window. Accepts `from`/`to` (Y-m-d) or falls back
     * to the last 30 days. Bounds are widened to whole days (UTC).
     */
    private function range(Request $request): array
    {
        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : Carbon::now();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : (clone $to)->subDays(29);

        return [$from->startOfDay(), $to->endOfDay()];
    }

    /**
     * One user's connected accounts across both stores (social_accounts + the
     * legacy connections table). Normalized shape; tokens never leave the API.
     */
    public function accounts(User $user): JsonResponse
    {
        $social = $user->socialAccounts()->orderBy('platform')->get()->map(fn ($a) => [
            'id' => 'social-'.$a->id,
            'platform' => $a->platform,
            'name' => $a->name,
            'username' => $a->username,
            'avatar_url' => $a->avatar_url,
            'url' => self::accountUrl($a->platform, $a->username, $a->platform_account_id, $a->profile_url),
            'status' => $a->status,
            'connected_at' => optional($a->created_at)->toISOString(),
        ]);

        $legacy = $user->connections()->orderBy('provider')->get()->map(fn ($c) => [
            'id' => 'legacy-'.$c->id,
            'platform' => $c->provider,
            'name' => $c->account_name,
            'username' => null,
            'avatar_url' => $c->avatar_url,
            'url' => self::accountUrl($c->provider, null, $c->account_id, null),
            'status' => 'connected',
            'connected_at' => optional($c->created_at)->toISOString(),
        ]);

        return response()->json(['data' => $social->concat($legacy)->values()]);
    }

    /**
     * Best public URL for an account: the stored profile_url when the platform
     * gave us one, else built from username / account id. Null when nothing
     * linkable exists (the UI then renders a plain chip).
     */
    private static function accountUrl(?string $platform, ?string $username, ?string $accountId, ?string $profileUrl): ?string
    {
        if ($profileUrl) {
            return $profileUrl;
        }
        $u = $username !== null && $username !== '' ? ltrim($username, '@') : null;

        return match ($platform) {
            'youtube' => $u ? "https://www.youtube.com/@{$u}" : ($accountId ? "https://www.youtube.com/channel/{$accountId}" : null),
            'tiktok' => $u ? "https://www.tiktok.com/@{$u}" : null,
            'instagram' => $u ? "https://www.instagram.com/{$u}/" : null,
            'x', 'twitter' => $u ? "https://x.com/{$u}" : null,
            'threads' => $u ? "https://www.threads.net/@{$u}" : null,
            'bluesky' => $u ? "https://bsky.app/profile/{$u}" : null,
            'facebook' => $accountId ? "https://www.facebook.com/{$accountId}" : null,
            default => null,
        };
    }

    /** One user + their roles, with the assignable roles list, for the edit page. */
    public function show(User $user): JsonResponse
    {
        return response()->json(['data' => [
            'user' => $this->userWithRoles($user),
            'roles' => \App\Models\Role::orderBy('name')->get(['id', 'name', 'display_name']),
        ]]);
    }

    /**
     * Replace the user's role. Single-role by design (the UI is a picker).
     * Guard: an admin cannot change their own role — no locking yourself out.
     */
    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate(['role' => 'required|string|exists:roles,name']);

        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        $role = \App\Models\Role::where('name', $validated['role'])->first();
        $user->roles()->sync([$role->id]);

        return response()->json(['data' => ['user' => $this->userWithRoles($user->fresh())]]);
    }

    private function userWithRoles(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'created_at' => optional($user->created_at)->toISOString(),
            'roles' => $user->roles()->pluck('name')->values(),
        ];
    }

    /**
     * Soft-delete a user. The row is retained (attribution/audit) but excluded
     * from auth and every default query. Guards: an admin cannot delete their own
     * account or another admin.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($request->user()?->id === $user->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if ($user->isAdmin()) {
            return response()->json(['message' => 'Admin accounts cannot be deleted.'], 422);
        }

        $user->delete();

        return response()->json(['data' => ['id' => $user->id, 'deleted' => true]]);
    }
}
