<?php

namespace App\Support;

/**
 * Why a user can't use ViewsMax right now (see UserAccess::denial), with the
 * one link that fixes it. toArray() is the REST error body.
 */
final readonly class AccessDenial
{
    public function __construct(
        public string $code,
        public string $message,
        public string $url,
    ) {}

    /** @return array{success: false, code: string, message: string, url: string} */
    public function toArray(): array
    {
        return [
            'success' => false,
            'code' => $this->code,
            'message' => $this->message,
            'url' => $this->url,
        ];
    }
}
