<?php

namespace App\Support;

/**
 * Why a user can't use ViewsMax right now (see UserAccess::denial), with the
 * one link that fixes it. toArray() is the REST error body.
 */
final readonly class AccessDenial
{
    /** Denials that paying fixes: delivered as HTTP 402 Payment Required on the REST API. */
    private const PAYMENT_CODES = [
        UserAccess::SUBSCRIPTION_INACTIVE,
        UserAccess::ACCESS_EXPIRED,
        UserAccess::PAYMENT_REQUIRED,
    ];

    public function __construct(
        public string $code,
        public string $message,
        public string $url,
    ) {}

    /** The HTTP status that delivers this denial on the REST API: 402 when paying fixes it, else 403. */
    public function status(): int
    {
        return in_array($this->code, self::PAYMENT_CODES, true) ? 402 : 403;
    }

    /**
     * The error object API clients and agents key off on a 402.
     *
     * @return array{code: string, message: string, upgrade_url: string}
     */
    public function error(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'upgrade_url' => $this->url,
        ];
    }

    /**
     * The flat keys are what the SPA reads; a 402 also carries `error`.
     *
     * @return array{success: false, code: string, message: string, url: string, error?: array{code: string, message: string, upgrade_url: string}}
     */
    public function toArray(): array
    {
        $body = [
            'success' => false,
            'code' => $this->code,
            'message' => $this->message,
            'url' => $this->url,
        ];

        return $this->status() === 402 ? $body + ['error' => $this->error()] : $body;
    }
}
