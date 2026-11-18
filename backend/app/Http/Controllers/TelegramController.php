<?php

namespace App\Http\Controllers;

use App\Services\Social\Providers\TelegramProvider;
use App\Services\Social\SocialProviderManager;
use App\Services\Social\Telegram\TelegramBotApi;
use App\Services\Social\Telegram\TelegramConnectCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * @group Connections
 *
 * Telegram's connect flow. There is no OAuth: the user gets a one-time code
 * here, adds the ViewsMax bot to their channel or group as an admin and posts
 * "/connect <code>" there. The bot's webhook sees the message; the user then
 * finishes with POST /api/social/telegram/connect {code}, which answers 202
 * `pending` until the code has been seen.
 */
class TelegramController extends Controller
{
    public function __construct(
        protected SocialProviderManager $manager,
        protected TelegramConnectCodes $codes,
        protected TelegramBotApi $api,
    ) {}

    /**
     * Get a one-time code to post in the channel or group. A new code
     * replaces the previous one; each lives 15 minutes.
     */
    public function connectCode()
    {
        if (! $this->manager->isAvailableTo(Auth::user(), 'telegram')) {
            return response()->json([
                'success' => false,
                'message' => 'Telegram is not available on your account yet.',
            ], 503);
        }

        $issued = $this->codes->issue(Auth::user());

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $issued['code'],
                'command' => '/connect '.$issued['code'],
                'bot_username' => $this->api->botUsername(),
                'bot_url' => 'https://t.me/'.$this->api->botUsername(),
                'expires_at' => $issued['expires_at']->toISOString(),
            ],
        ]);
    }

    /**
     * Bot API webhook (public). Telegram proves the call is its own with the
     * secret it was given at setWebhook (php artisan telegram:set-webhook).
     * Always 200 for a genuine call, so Telegram never re-delivers an update
     * we chose to ignore.
     *
     * @unauthenticated
     */
    public function webhook(Request $request)
    {
        if (! $this->manager->isConfigured('telegram')) {
            abort(404);
        }

        if (! hash_equals($this->api->webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token'))) {
            Log::warning('[telegram] webhook call with a wrong secret', ['ip' => $request->ip()]);
            abort(403);
        }

        /** @var TelegramProvider $provider */
        $provider = $this->manager->for('telegram');
        $provider->handleUpdate((array) $request->json()->all());

        return response()->json(['ok' => true]);
    }
}
