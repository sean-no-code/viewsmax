<?php

namespace App\Services\Automations\Data;

use App\Models\Automation;

/**
 * One normalized inbound event (a comment, a story reply or a DM) extracted
 * from a platform webhook payload. Platform-agnostic so the matcher/executor
 * never touch raw Meta JSON.
 */
final class InboundEvent
{
    public function __construct(
        public readonly string $platform,
        /** Platform id of the account that received the event (IG user id). */
        public readonly string $accountPlatformId,
        /** Automation::TRIGGER_COMMENT | TRIGGER_STORY_REPLY | TRIGGER_DM */
        public readonly string $type,
        /** Comment id or message mid — unique per event on the platform. */
        public readonly string $eventId,
        public readonly string $senderId,
        public readonly ?string $senderUsername,
        public readonly ?string $text,
        /** Comment media id / story id. */
        public readonly ?string $mediaId = null,
        /** Parent comment id when the comment is a reply inside a thread. */
        public readonly ?string $parentId = null,
        public readonly ?int $occurredAt = null,
    ) {}

    public function isComment(): bool
    {
        return $this->type === Automation::TRIGGER_COMMENT;
    }

    public function isThreadReply(): bool
    {
        return $this->isComment() && $this->parentId !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'account_platform_id' => $this->accountPlatformId,
            'type' => $this->type,
            'event_id' => $this->eventId,
            'sender_id' => $this->senderId,
            'sender_username' => $this->senderUsername,
            'text' => $this->text,
            'media_id' => $this->mediaId,
            'parent_id' => $this->parentId,
            'occurred_at' => $this->occurredAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            platform: (string) ($data['platform'] ?? 'instagram'),
            accountPlatformId: (string) ($data['account_platform_id'] ?? ''),
            type: (string) ($data['type'] ?? Automation::TRIGGER_DM),
            eventId: (string) ($data['event_id'] ?? ''),
            senderId: (string) ($data['sender_id'] ?? ''),
            senderUsername: $data['sender_username'] ?? null,
            text: $data['text'] ?? null,
            mediaId: $data['media_id'] ?? null,
            parentId: $data['parent_id'] ?? null,
            occurredAt: isset($data['occurred_at']) ? (int) $data['occurred_at'] : null,
        );
    }
}
