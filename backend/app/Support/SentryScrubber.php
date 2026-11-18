<?php

namespace App\Support;

use App\Services\Social\Providers\DiscordProvider;
use Sentry\Breadcrumb;
use Sentry\Event;

/**
 * Keeps secrets that live in request URLs out of Sentry. Sentry records every
 * outgoing HTTP call's URL path in breadcrumbs and spans, and a Discord
 * webhook's token is part of its path (/webhooks/{id}/{token}): posting
 * rights in someone's channel. Wired in config/sentry.php.
 */
final class SentryScrubber
{
    public static function breadcrumb(Breadcrumb $breadcrumb): ?Breadcrumb
    {
        $message = $breadcrumb->getMessage();
        if ($message !== null && ($clean = DiscordProvider::redact($message)) !== $message) {
            $breadcrumb = $breadcrumb->withMessage($clean);
        }

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            if (is_string($value) && ($clean = DiscordProvider::redact($value)) !== $value) {
                $breadcrumb = $breadcrumb->withMetadata($key, $clean);
            }
        }

        return $breadcrumb;
    }

    public static function transaction(Event $event): ?Event
    {
        foreach ($event->getSpans() as $span) {
            $description = $span->getDescription();
            if ($description !== null) {
                $span->setDescription(DiscordProvider::redact($description));
            }

            $data = $span->getData();
            array_walk_recursive($data, function (&$value) {
                if (is_string($value)) {
                    $value = DiscordProvider::redact($value);
                }
            });
            $span->setData($data);
        }

        return $event;
    }
}
