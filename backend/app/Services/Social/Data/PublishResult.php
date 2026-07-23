<?php

namespace App\Services\Social\Data;

/**
 * The normalised outcome of publishing a post to one account.
 */
class PublishResult
{
    public function __construct(
        public bool $success,
        public ?string $remotePostId = null,
        public ?string $remotePostUrl = null,
        public ?string $error = null,
        public array $response = [],
    ) {}

    public static function success(?string $remotePostId, ?string $remotePostUrl = null, array $response = []): self
    {
        return new self(true, $remotePostId, $remotePostUrl, null, $response);
    }

    public static function failure(string $error, array $response = []): self
    {
        return new self(false, null, null, $error, $response);
    }
}
