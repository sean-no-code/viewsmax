<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\StripeService;
use App\Services\SubscriptionStarter;
use App\Support\UserAccess;
use App\Support\UserSafeError;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Step 3 of the agent signup on the API host: pick a plan and add a card
 * before the consent screen. The same paywall the SPA's onboarding has
 * (UserAccess::PAYMENT_REQUIRED sends agent signups here), on the same terms:
 * inside the free window the card starts a trial — the activation fee today,
 * the plan price when the window closes (SubscriptionStarter).
 */
class WebBillingController extends Controller
{
    use Concerns\RemembersAgentSignup;

    public function show(Request $request, StripeService $stripe)
    {
        $user = $request->user();
        $client = $this->rememberAgent($request);

        // Nothing to pay here (card on file, promotional window, grandfathered).
        if (UserAccess::denial($user)?->code !== UserAccess::PAYMENT_REQUIRED) {
            return redirect()->route('register.continue');
        }

        $clientSecret = null;
        try {
            $clientSecret = $stripe->createSetupIntent($user)->client_secret;
        } catch (\Throwable $e) {
            report($e);
        }

        $terms = $user->subscriptionTrialTerms();

        return view('auth.billing', [
            'client' => $client,
            'hasAgent' => $request->session()->has(WebRegisterController::SESSION_AUTHORIZE_URL),
            'plans' => Plan::active()->where('price', '>', 0)->orderBy('price')->get(),
            'trialEnd' => isset($terms['trial_end']) ? Carbon::createFromTimestamp($terms['trial_end']) : null,
            'activationFee' => config('services.stripe.activation_fee_price_id') ? StripeService::ACTIVATION_FEE_USD : 0,
            'publishableKey' => config('services.stripe.key'),
            'clientSecret' => $clientSecret,
            'email' => $user->email,
        ]);
    }

    public function store(Request $request, SubscriptionStarter $starter)
    {
        $data = $request->validate([
            'payment_method_id' => 'required|string',
            'price_id' => 'required|string',
        ]);

        try {
            $starter->start($request->user(), $data['payment_method_id'], $data['price_id']);
        } catch (\Throwable $e) {
            Log::error('Agent signup card step failed', ['user_id' => $request->user()->id, 'error' => $e->getMessage()]);

            return redirect()->route('register.billing')->with('error', UserSafeError::withDetail("Couldn't start your subscription. ", $e));
        }

        return redirect()->route('register.continue');
    }
}
