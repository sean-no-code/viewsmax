<?php

namespace App\Http\Controllers;

use App\Exceptions\XSearchTierException;
use App\Models\SocialAccount;
use App\Services\Social\Providers\XProvider;
use App\Services\Social\SocialProviderManager;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * @group Connections
 *
 * X user search for the composer's @mention typeahead, proxied through the
 * user's own connected X account token. X gates /2/users/search to higher
 * API tiers — on a 403 the endpoint flips an app-level flag and degrades to
 * exact-username lookup, which lower tiers allow.
 */
class XUserSearchController extends Controller
{
    /** App-level (not per-user): the tier belongs to the app credentials. */
    private const TIER_FLAG = 'x-mention:search-blocked';

    public function __construct(protected SocialProviderManager $manager) {}

    /**
     * Search X users by handle prefix.
     *
     * @queryParam q string required The typed handle fragment, with or without a leading @. Example: jane
     * @queryParam social_account_id integer Search as a specific connected X account. Example: 1
     */
    public function search(Request $request)
    {
        $data = $request->validate([
            'q' => 'required|string|max:50',
            'social_account_id' => 'nullable|integer',
        ]);

        // Handles are [A-Za-z0-9_]; anything else can't be a mention token,
        // so answer empty without spending an upstream call.
        $q = ltrim(trim($data['q']), '@');
        if (! preg_match('/^[A-Za-z0-9_]{1,50}$/', $q)) {
            return $this->respond([], 'search', (bool) Cache::get(self::TIER_FLAG));
        }

        $account = $this->resolveAccount($data['social_account_id'] ?? null);
        if (! $account) {
            return response()->json([
                'success' => false,
                'message' => 'Connect an X account to search for people to mention.',
            ], 422);
        }

        /** @var XProvider $provider */
        $provider = $this->manager->for('x');
        $account = $provider->ensureFreshToken($account);
        if ($account->status !== SocialAccount::STATUS_CONNECTED) {
            return response()->json([
                'success' => false,
                'message' => 'Your X account needs to be reconnected.',
            ], 422);
        }

        try {
            if (! Cache::get(self::TIER_FLAG)) {
                try {
                    $users = Cache::remember(
                        "x-mention:search:{$account->id}:".md5(mb_strtolower($q)),
                        now()->addMinutes(10),
                        fn () => $provider->searchUsers($account, $q),
                    );

                    return $this->respond($users, 'search', false);
                } catch (XSearchTierException) {
                    Cache::put(self::TIER_FLAG, true, now()->addHours(12));
                }
            }

            // Degraded path: exact handle only.
            if (! preg_match('/^[A-Za-z0-9_]{1,15}$/', $q)) {
                return $this->respond([], 'lookup', true);
            }
            $result = Cache::remember(
                'x-mention:lookup:'.mb_strtolower($q),
                now()->addHour(),
                fn () => ['user' => $provider->lookupUsername($account, $q)],
            );

            return $this->respond($result['user'] ? [$result['user']] : [], 'lookup', true);
        } catch (RequestException $e) {
            if ($e->response->status() === 429) {
                return response()->json([
                    'success' => false,
                    'message' => 'X rate limit — try again shortly.',
                ], 429);
            }

            return response()->json([
                'success' => false,
                'message' => 'X user search failed.',
            ], 422);
        }
    }

    private function resolveAccount(?int $accountId): ?SocialAccount
    {
        $query = Auth::user()->socialAccounts()->where('platform', 'x');

        if ($accountId !== null) {
            return $query->where('id', $accountId)->first();
        }

        return $query->where('status', SocialAccount::STATUS_CONNECTED)->latest()->first();
    }

    private function respond(array $users, string $mode, bool $degraded)
    {
        return response()->json([
            'success' => true,
            'data' => [
                'users' => array_values($users),
                'mode' => $mode,
                'degraded' => $degraded,
            ],
        ]);
    }
}
