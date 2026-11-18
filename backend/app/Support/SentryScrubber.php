<?php

namespace App\Support;

use App\Services\Social\Telegram\TelegramBotApi;
use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Sentry hooks (config/sentry.php) that strip secrets which live in request
 * URLs. The SDK records every outgoing HTTP call as a breadcrumb (and, with
 * tracing on, a span) carrying its URL; the Telegram Bot API puts the bot
 * token in the URL path, so those must be scrubbed before anything is sent.
 */
final class SentryScrubber
{
    public static function breadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        if (($message = $breadcrumb->getMessage()) !== null) {
            $breadcrumb = $breadcrumb->withMessage(TelegramBotApi::redact($message));
        }

        foreach ($breadcrumb->getMetadata() as $name => $value) {
            if (is_string($value)) {
                $breadcrumb = $breadcrumb->withMetadata($name, TelegramBotApi::redact($value));
            }
        }

        return $breadcrumb;
    }

    public static function transaction(Event $event): ?Event
    {
        foreach ($event->getSpans() as $span) {
            if (($description = $span->getDescription()) !== null) {
                $span->setDescription(TelegramBotApi::redact($description));
            }
            $span->setData(array_map(
                fn ($value) => is_string($value) ? TelegramBotApi::redact($value) : $value,
                $span->getData(),
            ));
        }

        return $event;
    }
}
