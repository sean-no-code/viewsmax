<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    /**
     * Mark onboarding complete. Server-side gate: the user must have an
     * active/trialing subscription. Connecting an account is optional — users may
     * skip that step during onboarding — so it is not required here.
     */
    public function complete(Request $request)
    {
        $user = $request->user();

        if (! $user->hasActiveSubscription()) {
            return response()->json([
                'message' => 'Add payment to finish setting up your account.',
            ], 422);
        }

        if (is_null($user->onboarding_completed_at)) {
            $user->forceFill(['onboarding_completed_at' => now()])->save();
        }

        $user->load(['roles', 'plans']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user->apiPayload(),
            ],
        ]);
    }
}
