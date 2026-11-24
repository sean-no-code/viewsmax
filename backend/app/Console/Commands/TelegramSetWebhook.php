<?php

namespace App\Console\Commands;

use App\Services\Social\SocialProviderManager;
use App\Services\Social\Telegram\TelegramBotApi;
use App\Services\Social\Telegram\TelegramUnreachable;
use Illuminate\Console\Command;

/**
 * Point the ViewsMax bot's updates at /api/telegram/webhook. Run once per
 * environment after TELEGRAM_BOT_TOKEN is set, and again if the API host
 * changes. Only "message" and "channel_post" updates are requested: the
 * connect flow needs nothing else.
 *
 * @see https://core.telegram.org/bots/api#setwebhook
 */
class TelegramSetWebhook extends Command
{
    protected $signature = 'telegram:set-webhook
        {--url= : Public HTTPS URL of the webhook (default: this app\'s /api/telegram/webhook)}';

    protected $description = 'Register the Telegram bot webhook used by the Telegram connect flow';

    public function handle(SocialProviderManager $manager, TelegramBotApi $api): int
    {
        if (! $manager->isConfigured('telegram')) {
            $this->error('Telegram is not configured: set TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_USERNAME first.');

            return self::FAILURE;
        }

        $url = (string) ($this->option('url') ?: route('telegram.webhook'));
        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram only delivers webhooks over HTTPS; got {$url}. Pass --url with a public https address.");

            return self::FAILURE;
        }

        try {
            $response = $api->call('setWebhook', [
                'url' => $url,
                'secret_token' => $api->webhookSecret(),
                'allowed_updates' => ['message', 'channel_post'],
            ]);
        } catch (TelegramUnreachable) {
            $this->error('Telegram could not be reached.');

            return self::FAILURE;
        }

        if (! $response->ok) {
            $this->error("Telegram refused the webhook: {$response->description}");

            return self::FAILURE;
        }

        $this->info("Telegram webhook set to {$url}");

        return self::SUCCESS;
    }
}
