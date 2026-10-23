<?php

namespace App\Services\Social\Bluesky;

use RuntimeException;

/**
 * A publish was abandoned before createRecord ran (image download/upload,
 * unreadable image), so nothing reached Bluesky. The message is the final
 * user-facing target error.
 */
class NothingPublishedException extends RuntimeException {}
