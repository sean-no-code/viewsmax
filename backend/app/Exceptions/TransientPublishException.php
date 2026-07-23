<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A publish attempt failed for a transient reason (rate limit, 5xx). Publish
 * jobs rethrow this so the queue retries; progress persisted before the throw
 * (e.g. X thread tweet ids) lets the retry resume where it left off.
 */
class TransientPublishException extends RuntimeException {}
