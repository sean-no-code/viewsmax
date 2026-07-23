<?php

namespace App\Services\Social\Data;

class CommentResult
{
    public function __construct(
        public bool $success,
        public ?string $remoteCommentId = null,
        public ?string $error = null,
        public array $response = [],
    ) {}

    public static function success(?string $remoteCommentId, array $response = []): self
    {
        return new self(true, $remoteCommentId, null, $response);
    }

    public static function failure(string $error, array $response = []): self
    {
        return new self(false, null, $error, $response);
    }
}
