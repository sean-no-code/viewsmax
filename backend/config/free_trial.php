<?php

/*
|--------------------------------------------------------------------------
| Free trial (card-free window)
|--------------------------------------------------------------------------
|
| A new signup gets User::CARD_FREE_DAYS of access with no card, recorded as
| users.promo_expires_at. When the window closes without a plan the REST API
| (EnsureAccessActive) and the MCP server (SafeCallTool) lock the account to
| the Billing page. No email goes out before that happens. Users who already
| added a card get the Stripe "card about to be charged" reminder
| (subscriptions:send-trial-reminders).
|
*/

return [

    // SIGNUP_CARD_REQUIRED=false: a card-free trial. Onboarding finishes
    // without a card and the whole product works until the window closes,
    // then the account goes to Billing as before. true (the default) keeps
    // the card step: no access past onboarding until a card is added. Users
    // who finished onboarding while it was off keep access until their
    // window ends either way. Read by App\Support\UserAccess::cardRequired().
    'card_required' => (bool) env('SIGNUP_CARD_REQUIRED', true),

];
