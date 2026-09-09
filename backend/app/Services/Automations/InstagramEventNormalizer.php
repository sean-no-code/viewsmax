<?php

namespace App\Services\Automations;

use App\Models\Automation;
use App\Services\Automations\Data\InboundEvent;

/**
 * Raw Instagram webhook batch → normalized InboundEvents. Drops everything
 * that must never trigger an automation: our own comments/replies, echoes
 * of our own DMs, reactions/read receipts/postbacks, and events without
 * an id.
 */
class InstagramEventNormalizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, InboundEvent>
     */
    public function normalize(array $payload): array
    {
        if (($payload['object'] ?? null) !== 'instagram') {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $accountId = (string) ($entry['id'] ?? '');
            if ($accountId === '') {
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) === 'comments' && is_array($change['value'] ?? null)) {
                    $event = $this->comment($accountId, $change['value'], $entry['time'] ?? null);
                    if ($event) {
                        $events[] = $event;
                    }
                }
            }

            foreach ($entry['messaging'] ?? [] as $messaging) {
                $event = $this->message($accountId, $messaging);
                if ($event) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /** @param  array<string, mixed>  $value */
    protected function comment(string $accountId, array $value, mixed $time): ?InboundEvent
    {
        $commentId = (string) ($value['id'] ?? '');
        $senderId = (string) data_get($value, 'from.id', '');

        // Our own comments / public replies come back through the same webhook.
        if ($commentId === '' || $senderId === '' || $senderId === $accountId) {
            return null;
        }

        return new InboundEvent(
            platform: 'instagram',
            accountPlatformId: $accountId,
            type: Automation::TRIGGER_COMMENT,
            eventId: $commentId,
            senderId: $senderId,
            senderUsername: data_get($value, 'from.username'),
            text: isset($value['text']) ? (string) $value['text'] : null,
            mediaId: data_get($value, 'media.id') !== null ? (string) data_get($value, 'media.id') : null,
            parentId: isset($value['parent_id']) ? (string) $value['parent_id'] : null,
            occurredAt: is_numeric($time) ? (int) $time : null,
        );
    }

    /** @param  array<string, mixed>  $messaging */
    protected function message(string $accountId, array $messaging): ?InboundEvent
    {
        $message = $messaging['message'] ?? null;
        // Reactions, read receipts, postbacks etc. carry no `message` key.
        if (! is_array($message)) {
            return null;
        }

        $senderId = (string) data_get($messaging, 'sender.id', '');
        $mid = (string) ($message['mid'] ?? '');

        // Echoes are our own outbound DMs; sender == account is the same thing.
        if ($mid === '' || $senderId === '' || $senderId === $accountId || ! empty($message['is_echo'])) {
            return null;
        }

        $story = data_get($message, 'reply_to.story');
        $timestamp = $messaging['timestamp'] ?? null;

        return new InboundEvent(
            platform: 'instagram',
            accountPlatformId: $accountId,
            type: is_array($story) ? Automation::TRIGGER_STORY_REPLY : Automation::TRIGGER_DM,
            eventId: $mid,
            senderId: $senderId,
            senderUsername: null,
            text: isset($message['text']) ? (string) $message['text'] : null,
            mediaId: is_array($story) && isset($story['id']) ? (string) $story['id'] : null,
            // Meta sends ms; normalize to seconds.
            occurredAt: is_numeric($timestamp) ? (int) ($timestamp > 9999999999 ? $timestamp / 1000 : $timestamp) : null,
        );
    }
}
