<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Gemini video request that didn't produce a usable result. The message is
 * written for end users (it is stored on the breakdown row and returned by the
 * free transcript tool); the raw provider error only goes to the `gemini` log.
 *
 * `reportable` separates our bugs (bad response, blocked prompt) from the
 * provider's answers (private video, busy, timeout), which must not page Sentry.
 */
class GeminiVideoException extends RuntimeException
{
    public const VIDEO_UNAVAILABLE = 'video_unavailable';

    public const MEDIA_UNAVAILABLE = 'media_unavailable';

    public const TOO_LARGE = 'too_large';

    public const BUSY = 'busy';

    public const TIMEOUT = 'timeout';

    public const BLOCKED = 'blocked';

    public const BAD_RESPONSE = 'bad_response';

    /**
     * @param  string  $detail  Internal context for the log line (provider text, HTTP status). Never shown to users.
     */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly bool $reportable = true,
        ?\Throwable $previous = null,
        public readonly string $detail = '',
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Picked up by Laravel's exception handler and Sentry when the exception is
     * report()ed, so laravel.log / Sentry carry the provider's answer and not
     * only the user-facing sentence.
     *
     * @return array{reason: string, detail: string}
     */
    public function context(): array
    {
        return ['reason' => $this->reason, 'detail' => $this->detail];
    }

    /** Private, removed, age-gated, or a URL Google can't fetch. The video's fault, not ours. */
    public static function videoUnavailable(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::VIDEO_UNAVAILABLE, "We couldn't access this video. It may be private, age-restricted, removed, or not supported.", false, $previous, $detail);
    }

    /** CaptAPI gave us no downloadable file, or the download itself failed. */
    public static function mediaUnavailable(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::MEDIA_UNAVAILABLE, "We couldn't download this video to analyse it. Please try again later.", true, $previous, $detail);
    }

    public static function tooLarge(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::TOO_LARGE, 'This video is too large to analyse.', false, $previous, $detail);
    }

    public static function busy(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::BUSY, 'The video analysis service is busy. Please try again in a minute.', false, $previous, $detail);
    }

    public static function timeout(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::TIMEOUT, 'The video analysis took too long. Please try again in a minute.', false, $previous, $detail);
    }

    public static function blocked(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::BLOCKED, "This video couldn't be analysed.", true, $previous, $detail);
    }

    public static function badResponse(string $detail = '', ?\Throwable $previous = null): self
    {
        return new self(self::BAD_RESPONSE, 'The video analysis failed. Please try again in a few minutes.', true, $previous, $detail);
    }
}
