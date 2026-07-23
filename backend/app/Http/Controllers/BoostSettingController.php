<?php

namespace App\Http\Controllers;

use App\Models\BoostCheck;
use App\Models\BoostSetting;
use App\Services\Social\CaptionRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * @group Boosts
 *
 * Per-account Boost automations (X-only v1): Auto Repost retweets a post once
 * it reaches a like threshold; Auto Promo replies to it with a promo comment.
 * Checks run 6h apart, up to 3 times per post, and stop on success.
 */
class BoostSettingController extends Controller
{
    /** All of the user's boost settings, keyed for the Boosts page. */
    public function index(): JsonResponse
    {
        $settings = BoostSetting::with('socialAccount')
            ->where('user_id', Auth::id())
            ->get();

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $settings]);
    }

    /** Create or update one feature's setting on one connected account. */
    public function update(Request $request, int $socialAccountId): JsonResponse
    {
        $account = Auth::user()->socialAccounts()
            ->where('platform', 'x')
            ->findOrFail($socialAccountId);

        $data = $request->validate([
            'feature' => ['required', Rule::in(BoostSetting::FEATURES)],
            'enabled' => 'required|boolean',
            'likes_threshold' => 'required|integer|min:1|max:1000000',
            'promo_text' => 'nullable|string',
        ]);

        if ($data['feature'] === BoostSetting::FEATURE_AUTO_PROMO && $data['enabled']) {
            $promo = trim((string) ($data['promo_text'] ?? ''));
            if ($promo === '') {
                abort(response()->json(['message' => 'Auto Promo needs the comment text it should post.'], 422));
            }
            if (CaptionRules::xLength($promo) > CaptionRules::LIMITS['x']) {
                abort(response()->json(['message' => "The promo comment is over X's ".CaptionRules::LIMITS['x'].' character limit.'], 422));
            }
        }

        $setting = BoostSetting::updateOrCreate(
            ['social_account_id' => $account->id, 'feature' => $data['feature']],
            [
                'user_id' => Auth::id(),
                'enabled' => $data['enabled'],
                'likes_threshold' => $data['likes_threshold'],
                'promo_text' => $data['promo_text'] ?? null,
            ]
        );

        return response()->json(['success' => true, 'message' => 'Boost setting saved.', 'data' => $setting]);
    }

    /** Recent boost activity (triggered/exhausted/failed checks), newest first. */
    public function activity(): JsonResponse
    {
        $checks = BoostCheck::with(['target.post', 'setting'])
            ->whereHas('setting', fn ($q) => $q->where('user_id', Auth::id()))
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return response()->json(['success' => true, 'message' => 'OK', 'data' => $checks]);
    }
}
