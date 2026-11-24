<?php

namespace App\Services\Social\Telegram;

use Illuminate\Http\Client\Response;

/**
 * One Bot API answer: {"ok": true, "result": ...} or {"ok": false,
 * "error_code": 403, "description": "Forbidden: bot was kicked from the
 * channel chat", "parameters": {"retry_after": 7}}.
 *
 * @see https://core.telegram.org/bots/api#making-requests
 * @see https://core.telegram.org/bots/api#responseparameters
 */
final class TelegramResponse
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly mixed $result,
        public readonly string $description,
        public readonly ?int $retryAfter,
        public readonly ?string $migrateToChatId,
        public readonly array $body,
    ) {}

    public static function from(Response $response): self
    {
        $body = (array) ($response->json() ?? []);
        $ok = $response->successful() && ($body['ok'] ?? false) === true;
        $migrate = $body['parameters']['migrate_to_chat_id'] ?? null;

        return new self(
            ok: $ok,
            // error_code mirrors the HTTP status; Telegram says its contents may change, so the status wins.
            status: $response->status(),
            result: $body['result'] ?? null,
            description: TelegramBotApi::redact(trim((string) ($body['description'] ?? '')) ?: 'HTTP '.$response->status()),
            retryAfter: isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null,
            migrateToChatId: $migrate !== null ? (string) $migrate : null,
            body: $body,
        );
    }
}
