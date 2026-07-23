<?php

namespace App\Services\Social\Data;

/**
 * The normalised outcome of an OAuth token exchange or refresh.
 */
class OAuthResult
{
    public function __construct(
        public ?string $accessToken,
        public ?string $refreshToken = null,
        public ?int $expiresIn = null,
        public array $scopes = [],
        public array $raw = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $scopes = $data['scope'] ?? $data['scopes'] ?? [];
        if (is_string($scopes)) {
            $scopes = preg_split('/[\s,]+/', trim($scopes)) ?: [];
        }

        return new self(
            accessToken: $data['access_token'] ?? null,
            refreshToken: $data['refresh_token'] ?? null,
            expiresIn: isset($data['expires_in']) ? (int) $data['expires_in'] : null,
            scopes: array_values(array_filter((array) $scopes)),
            raw: $data,
        );
    }

    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->expiresIn ? now()->addSeconds($this->expiresIn) : null;
    }
}
