<?php

/*
|--------------------------------------------------------------------------
| Free trial (card-free window)
|--------------------------------------------------------------------------
|
| A new signup gets User::CARD_FREE_DAYS of access with no card, recorded as
| users.promo_expires_at. When the window closes without a plan the REST API
| (EnsureAccessActive) and the MCP server (SafeCallTool) lock the account to
| the Billing page. The hourly subscriptions:send-free-trial-reminders command
| emails each user once, shortly before that happens. Users who already added
| a card get the Stripe "card about to be charged" reminder instead
| (subscriptions:send-trial-reminders) and are skipped here.
|
*/

return [

    'reminder' => [
        // FREE_TRIAL_REMINDER_ENABLED=false stops the emails without a deploy.
        'enabled' => (bool) env('FREE_TRIAL_REMINDER_ENABLED', true),

        // Send once the window ends within this many hours.
        'hours_before' => (int) env('FREE_TRIAL_REMINDER_HOURS_BEFORE', 24),
    ],

];
